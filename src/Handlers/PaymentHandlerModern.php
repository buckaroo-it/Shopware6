<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\PaymentMethods\AbstractPayment;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Service\Exceptions\BuckarooPaymentRejectException;
use Buckaroo\Shopware6\Service\Exceptions\ClientInitException;
use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentHandlerModern extends AbstractPaymentHandler
{
    use \Buckaroo\Shopware6\Buckaroo\Traits\Validation\ValidateOrderTrait;
    
    protected string $paymentClass;
    protected FormatRequestParamService $formatRequestParamService;
    protected AsyncPaymentService $asyncPaymentService;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->asyncPaymentService = $asyncPaymentService;
        $this->formatRequestParamService = $this->asyncPaymentService->formatRequestParamService;
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

            if ($this->getOrderTotalWithFee($order, $order->getSalesChannelId(), $paymentCode) == 0) {
                return $this->completeZeroAmountPayment($orderTransaction, $salesChannelContext);
            }

            $commonPayload = $this->getCommonRequestPayload(
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
                ->setAction(
                    $this->getMethodAction($dataBag, $salesChannelContext, $paymentCode)
                );
            

            if ($paymentCode === 'afterpay' && !$this->isAfterpayOld($salesChannelContext->getSalesChannelId())) {
                $client->setServiceVersion(2);
            }

      
            $response = $client->execute();
            
            return $this->handleResponse(
                $response,
                $orderTransaction,
                $order,
                $dataBag,
                $salesChannelContext,
                $paymentCode
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
        return $this->formatRequestParamService->getOrderLinesArray($order, $paymentCode, $context);
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

    protected function getCommonRequestPayload(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode,
        ?string $returnUrl
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        return [
            'order'         => $order->getOrderNumber(),
            'invoice'       => $order->getOrderNumber(),
            'amountDebit'   => $this->getOrderTotalWithFee($order, $salesChannelId, $paymentCode),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $returnUrl ?: $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag),
            'cancelURL'     => sprintf(
                '%s&cancel=1',
                $returnUrl ?: $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag)
            ),
            'pushURL'       => $this->asyncPaymentService->urlService->getReturnUrl('buckaroo.payment.push'),
            'additionalParameters' => [
                'orderTransactionId' => $orderTransaction->getId(),
                'orderId' => $order->getId(),
                'sw-context-token' => $salesChannelContext->getToken()
            ],
            'description' => $this->asyncPaymentService->settingsService->getParsedLabel(
                $order,
                $salesChannelId,
                'transactionLabel'
            ),
            'clientIP' => $this->getIp(),
        ];
    }

    private function getOrderTotalWithFee(OrderEntity $order, string $salesChannelId, string $paymentCode): float
    {
        $fee =  $this->getFee($paymentCode, $salesChannelId);
        $existingFee = $order->getCustomFieldsValue('buckarooFee');
        if ($existingFee !== null && is_scalar($existingFee)) {
            $fee = $fee - (float)$existingFee;
        }
        return $order->getAmountTotal() + $fee;
    }

    private function getIp(): array
    {
        $request = Request::createFromGlobals();
        $remoteIp = $request->getClientIp();
        return [
            'address'       => $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }

    protected function getFee(string $paymentCode, string $salesChannelId): float
    {
        return $this->asyncPaymentService->settingsService->getBuckarooFee($paymentCode, $salesChannelId);
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

    private function completeZeroAmountPayment(
        OrderTransactionEntity $orderTransaction,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $this->asyncPaymentService->stateTransitionService->transitionPaymentState(
            'paid',
            $orderTransaction->getId(),
            $salesChannelContext->getContext()
        );
        return $this->redirectToFinishPage($orderTransaction);
    }

    private function redirectToFinishPage(OrderTransactionEntity $orderTransaction): RedirectResponse
    {
        $order = $orderTransaction->getOrder();
        if ($order === null) {
            throw new \RuntimeException('Order not found for transaction');
        }
        return new RedirectResponse(
            $this->asyncPaymentService->urlService->forwardToRoute(
                'frontend.checkout.finish.page',
                ['orderId' => $order->getId()]
            )
        );
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $returnUrl = $this->getReturnUrl($orderTransaction, $order, $dataBag);
        $this->storeTransactionInfo($orderTransaction, $order, $response, $salesChannelContext, $paymentCode);
  
        if ($response->isRejected()) {
            throw new BuckarooPaymentRejectException($response->getSubCodeMessage());
        }

        if ($response->hasRedirect()) {
            $this->handleRedirectResponse($orderTransaction);
            return new RedirectResponse($response->getRedirectUrl());
        }
        return $this->handlePaymentStatus($response, $orderTransaction, $salesChannelContext, $returnUrl, $paymentCode);
    }

    private function storeTransactionInfo(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        ClientResponseInterface $response,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $this->asyncPaymentService->checkoutHelper->appendCustomFields(
            $order->getId(),
            [
                'buckaroo_payment_in_test_mode' => $response->isTestMode(),
            ],
            $salesChannelContext->getContext()
        );
        $this->asyncPaymentService->transactionService->updateTransactionCustomFields($orderTransaction->getId(), [
            'originalTransactionKey' => $response->getTransactionKey()
        ]);
        $this->applyFeeToOrder($orderTransaction, $order, $salesChannelContext, $paymentCode);
    }

    private function handlePaymentStatus(
        ClientResponseInterface $response,
        OrderTransactionEntity $orderTransaction,
        SalesChannelContext $salesChannelContext,
        string $returnUrl,
        string $paymentCode
    ): RedirectResponse {
        if (
            $response->isSuccess() ||
            $response->isAwaitingConsumer() ||
            $response->isPendingProcessing() ||
            $response->isWaitingOnUserInput()
        ) {
            if (!$response->isSuccess()) {
                $this->asyncPaymentService->stateTransitionService->transitionPaymentState(
                    'pending',
                    $orderTransaction->getId(),
                    $salesChannelContext->getContext()
                );
            }
            return $this->redirectToFinishPage($orderTransaction);
        }

        if ($response->isCanceled()) {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransaction->getId(),
                'Payment was canceled',
                new \Exception('Payment was canceled')
            );
        }

        return new RedirectResponse(
            sprintf('%s&brq_payment_method=%s&brq_statuscode=%s', $returnUrl, $paymentCode, $response->getStatusCode())
        );
    }

    private function handleRedirectResponse(OrderTransactionEntity $orderTransaction): void
    {
        $order = $orderTransaction->getOrder();
        if ($order === null) {
            return;
        }
        $this->asyncPaymentService
            ->checkoutHelper
            ->getSession()
            ->set('buckaroo_latest_order', $order->getId());
    }

    private function applyFeeToOrder(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $fee = $this->getFee($paymentCode, $salesChannelContext->getSalesChannelId());
        $this->asyncPaymentService
            ->checkoutHelper
            ->applyFeeToOrder($order->getId(), $fee, $salesChannelContext->getContext());
    }

    protected function getRequestBag(RequestDataBag $currentBag): RequestDataBag
    {
        if ($this->isUpdateOrder($currentBag)) {
            $request = new Request($_GET, $_POST);
            return new RequestDataBag($request->request->all());
        }
        return $currentBag;
    }

    protected function getDataBag(DataBag $currentBag): RequestDataBag
    {
        $requestDataBag = new RequestDataBag($currentBag->all());
        if ($this->isUpdateOrder($requestDataBag)) {
            $request = new Request($_GET, $_POST);
            return new RequestDataBag($request->request->all());
        }
        return $requestDataBag;
    }

    protected function isUpdateOrder(RequestDataBag $bag): bool
    {
        return $bag->has('errorUrl') &&
            is_scalar($bag->get('errorUrl')) &&
            strstr((string)$bag->get('errorUrl'), '/account/order/edit/') !== false;
    }

    protected function getReturnUrl(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        RequestDataBag $dataBag
    ): string {
        if ($dataBag->has('finishUrl') && is_scalar($dataBag->get('finishUrl'))) {
            $finishUrl = (string)$dataBag->get('finishUrl');
            if (strpos($finishUrl, 'http://') === 0 || strpos($finishUrl, 'https://') === 0) {
                return $finishUrl;
            }
            return rtrim(
                $this->asyncPaymentService->urlService->forwardToRoute('frontend.home.page', []),
                "/"
            ) . (string)$finishUrl;
        }
        return $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag);
    }

    protected function getDefaultReturnUrl(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        DataBag $dataBag
    ): string {
        return $this->asyncPaymentService->urlService->forwardToRoute(
            'frontend.checkout.finish.page',
            ['orderId' => $order->getId()]
        );
    }
}
