<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\PaymentMethods\AbstractPayment;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\Exceptions\BuckarooPaymentRejectException;
use Buckaroo\Shopware6\Service\Exceptions\ClientInitException;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class PaymentHandlerModernRefactored extends AbstractPaymentHandler
{
    use \Buckaroo\Shopware6\Buckaroo\Traits\Validation\ValidateOrderTrait;
    
    protected string $paymentClass;

    public function __construct(
        private readonly AsyncPaymentService $asyncPaymentService,
        private readonly PaymentUrlGenerator $urlGenerator,
        private readonly PaymentFeeCalculator $feeCalculator,
        private readonly PaymentPayloadBuilder $payloadBuilder,
        private readonly PaymentResponseHandler $responseHandler
    ) {
    }

    /**
     * Expose a way for specific method handlers to set their Buckaroo class.
     */
    public function setPaymentClass(string $paymentClass): void
    {
        $this->paymentClass = $paymentClass;
    }

    public function supports(
        mixed $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $this->shouldSupport($paymentMethodId, $context);
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $dataBag = new RequestDataBag($request->request->all());

        $this->beforePayModern($transaction, $dataBag, $context);
        
        $transactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->asyncPaymentService->getTransaction($transactionId, $context);
        if ($orderTransaction === null) {
            throw new InvalidParameterException('Order transaction not found');
        }

        $order = $orderTransaction->getOrder();
        if ($order === null) {
            throw new InvalidParameterException('Order not found');
        }

        $contextToken = $request->get('sw-context-token', '');
        $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
            $context,
            $order->getSalesChannelId(),
            is_string($contextToken) ? $contextToken : ''
        );
        
        $paymentClass = $this->getPayment($transactionId);
        $paymentCode = $paymentClass->getBuckarooKey();

        $this->asyncPaymentService->cancelPreviousPayments($transaction, $order);

        try {
            $this->validateOrder($order);

            if ($this->feeCalculator->getOrderTotalWithFee($order, $order->getSalesChannelId(), $paymentCode) == 0) {
                return $this->responseHandler->handleZeroAmountPayment($orderTransaction, $salesChannelContext);
            }

            $commonPayload = $this->payloadBuilder->buildCommonPayload(
                $orderTransaction,
                $order,
                $dataBag,
                $salesChannelContext,
                $paymentCode,
                $transaction->getReturnUrl()
            );
            
            $methodPayload = $this->getMethodPayload($order, $dataBag, $salesChannelContext, $paymentCode);
            $payload = array_merge_recursive($commonPayload, $methodPayload);

            $client = $this->getClient($paymentCode, $order->getSalesChannelId(), $dataBag)
                ->setPayload($payload)
                ->setAction($this->getMethodAction($dataBag, $salesChannelContext, $paymentCode));

            if ($paymentCode === 'afterpay' && !$this->isAfterpayOld($salesChannelContext->getSalesChannelId())) {
                $client->setServiceVersion(2);
            }

            $response = $client->execute();
            $returnUrl = $this->urlGenerator->getReturnUrl($orderTransaction, $order, $dataBag);
            
            return $this->responseHandler->handleResponse(
                $response,
                $orderTransaction,
                $order,
                $salesChannelContext,
                $paymentCode,
                $returnUrl
            );
        } catch (PaymentException $exception) {
            throw $exception;
        } catch (BuckarooPaymentRejectException $exception) {
            throw PaymentException::asyncProcessInterrupted($transactionId, $exception->getMessage(), $exception);
        } catch (ClientInitException $exception) {
            throw PaymentException::asyncProcessInterrupted($transactionId, $exception->getMessage(), $exception);
        } catch (CreateCartException $exception) {
            throw PaymentException::asyncProcessInterrupted($transactionId, $exception->getMessage(), $exception);
        }
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $this->beforeFinalizeModern($transaction, $request, $context);
        $this->asyncPaymentService->paymentStateService->finalizePayment(
            $transaction,
            $request,
            $context
        );
        $this->afterFinalizeModern($transaction, $request, $context);
    }

    /**
     * Hook for specific handlers to run logic before modern pay flow.
     */
    protected function beforePayModern(
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        Context $context
    ): void {
    }

    /**
     * Optional supports hook (default true).
     */
    protected function shouldSupport(string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    protected function beforeFinalizeModern(
        PaymentTransactionStruct $transaction,
        Request $request,
        Context $context
    ): void {
        // Default no-op
    }

    protected function afterFinalizeModern(
        PaymentTransactionStruct $transaction,
        Request $request,
        Context $context
    ): void {
        // Default no-op
    }

    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        return 'pay';
    }

    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [];
    }

    /**
     * Exposed for handlers like AfterPay that need order lines.
     * @return array<mixed>
     */
    protected function getOrderLinesArray(
        OrderEntity $order,
        string $paymentCode,
        ?Context $context = null
    ): array {
        return $this->asyncPaymentService->formatRequestParamService->getOrderLinesArray($order, $paymentCode, $context);
    }

    /**
     * Access plugin settings for handlers.
     * @param string $key
     * @param string|null $salesChannelId
     * @return mixed
     */
    protected function getSetting(string $key, string $salesChannelId = null)
    {
        return $this->asyncPaymentService->settingsService->getSetting($key, $salesChannelId);
    }

    /**
     * AfterPay version toggle based on settings.
     */
    protected function isAfterpayOld(string $salesChannelContextId): bool
    {
        return $this->getSetting('afterpayEnabledold', $salesChannelContextId) === true;
    }

    private function getClient(string $paymentCode, string $salesChannelId, RequestDataBag $dataBag): Client
    {
        if (
            $paymentCode === 'paybybank' &&
            $dataBag->get('payBybankMethodId') === 'INGBNL2A' &&
            $this->asyncPaymentService->isMobile(Request::createFromGlobals())
        ) {
            $paymentCode = 'ideal';
        }
        return $this->asyncPaymentService->clientService->get($paymentCode, $salesChannelId);
    }

    private function getPayment(string $transactionId): AbstractPayment
    {
        $paymentClass = null;
        if (class_exists($this->paymentClass)) {
            $paymentClass = new $this->paymentClass();
        }
        if ($paymentClass === null || !$paymentClass instanceof AbstractPayment) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Invalid buckaroo payment class',
                new \Exception('Invalid buckaroo payment class')
            );
        }
        return $paymentClass;
    }
}
