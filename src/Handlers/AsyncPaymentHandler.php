<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Buckaroo\Client;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\PaymentMethods\AbstractPayment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Buckaroo\Shopware6\Events\AfterPaymentRequestEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Events\BeforePaymentRequestEvent;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelContextServiceInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Buckaroo\Traits\Validation\ValidateOrderTrait;
use Buckaroo\Shopware6\Service\Exceptions\BuckarooPaymentRejectException;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\Response;

class AsyncPaymentHandler extends AbstractPaymentHandler
{
    use ValidateOrderTrait;

    protected string $paymentClass;

    protected AsyncPaymentService $asyncPaymentService;

    protected FormatRequestParamService $formatRequestParamService;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->asyncPaymentService = $asyncPaymentService;
        $this->formatRequestParamService = $this->asyncPaymentService->formatRequestParamService;
    }

    /**
     * @param PaymentHandlerType $type
     * @param string $paymentMethodId
     * @param Context $context
     * @return bool
     */
    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        // Support asynchronous payment processing (redirects)
        return $type === PaymentHandlerType::ASYNCHRONOUS;
    }
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $dataBag = new RequestDataBag($request->request->all());
        $transactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->asyncPaymentService->getTransaction($transactionId, $context);
        $order = $orderTransaction->getOrder();
        $salesChannelContext = $request->get('sw-sales-channel-context');
        $paymentClass = $this->getPayment($transactionId);
        $paymentCode = $paymentClass->getBuckarooKey();
        $this->asyncPaymentService->cancelPreviousPayments($transaction,$order);

        try {
            $this->validateOrder($order);
            if ($this->getOrderTotalWithFee(
                    $order,
                    $order->getSalesChannelId(),
                    $paymentCode
                ) == 0) {
                return $this->completeZeroAmountPayment($orderTransaction, $salesChannelContext);
            }
            $client = $this->getClient(
                $paymentCode,
                $order->getSalesChannelId(),
                $dataBag
            )
                ->setPayload(
                    array_merge_recursive(
                        $this->getCommonRequestPayload(
                            $orderTransaction,
                            $order,
                            $dataBag,
                            $salesChannelContext,
                            $paymentCode,
                            $transaction->getReturnUrl()
                        ),
                        $this->getMethodPayload(
                            $order,
                            $dataBag,
                            $salesChannelContext,
                            $paymentCode
                        )
                    )
                )
                ->setAction(
                    $this->getMethodAction(
                        $dataBag,
                        $salesChannelContext,
                        $paymentCode
                    )
                );
            if (
                $paymentCode === "afterpay" &&
                !$this->isAfterpayOld($salesChannelContext->getSalesChannelId())
            ) {
                $client->setServiceVersion(2);
            }

            $this->asyncPaymentService->dispatchEvent(
                new BeforePaymentRequestEvent(
                    $transaction,       // <-- pass $transaction here (PaymentTransactionStruct)
                    $dataBag,
                    $salesChannelContext,
                    $client
                )
            );
            return $this->handleResponse(
                $client->execute(),
                $transaction,
                $order,
                $dataBag,
                $salesChannelContext,
                $paymentCode
            );
        } catch (BuckarooPaymentRejectException $e) {
            $this->asyncPaymentService->logger->error((string) $e->getMessage());
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                $e->getMessage(),
                $e
            );
        } catch (\Throwable $th) {
            $this->asyncPaymentService->logger->error((string) $th);
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Cannot create buckaroo payment',
                $th
            );
        }
    }


    protected function handleResponse(
        ClientResponseInterface $response,
        $orderTransaction,
        $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $this->asyncPaymentService->dispatchEvent(
            new AfterPaymentRequestEvent(
                $orderTransaction,
                $dataBag,
                $salesChannelContext,
                $response,
                $paymentCode
            )
        );
        $returnUrl = $this->getReturnUrl($orderTransaction, $order, $dataBag);
        $this->storeTransactionInfo($orderTransaction, $order, $response, $salesChannelContext, $paymentCode);

        if ($response->isRejected()) {
            throw new BuckarooPaymentRejectException($response->getSubCodeMessage());
        }
        
        if ($response->hasRedirect()) {
            $this->handleRedirectResponse($order);
            return new RedirectResponse($response->getRedirectUrl());
        }

        return $this->handlePaymentStatus($response, $orderTransaction, $salesChannelContext, $returnUrl, $paymentCode);
    }

    private function storeTransactionInfo(
        $orderTransaction,
        $order,
        ClientResponseInterface $response,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $this->asyncPaymentService
            ->checkoutHelper
            ->appendCustomFields(
                $order->getId(),
                [
                    'buckaroo_payment_in_test_mode' => $response->isTestMode(),
                ],
                $salesChannelContext->getContext()
            );
        $this->asyncPaymentService->transactionService
            ->updateTransactionCustomFields($orderTransaction->getOrderTransactionId(), [
                'originalTransactionKey' => $response->getTransactionKey()
            ]);
        $this->setFeeOnOrder($orderTransaction, $order, $salesChannelContext, $paymentCode);
    }

    private function handleRedirectResponse(
        $order
    ): void {
        $this->asyncPaymentService
            ->checkoutHelper
            ->getSession()
            ->set('buckaroo_latest_order', $order->getId());
    }

    private function handlePaymentStatus(
        ClientResponseInterface $response,
        $orderTransaction,
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
                $this->asyncPaymentService
                    ->stateTransitionService
                    ->transitionPaymentState(
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
                'Payment was canceled'
            );
        }

        return new RedirectResponse(
            sprintf(
                "%s&brq_payment_method={$paymentCode}&brq_statuscode=" . $response->getStatusCode(),
                $returnUrl
            )
        );
    }

    private function completeZeroAmountPayment(
        $orderTransaction,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $this->asyncPaymentService
            ->stateTransitionService
            ->transitionPaymentState(
                'paid',
                $orderTransaction->getId(),
                $salesChannelContext->getContext()
            );
        return $this->redirectToFinishPage($orderTransaction);
    }

    private function redirectToFinishPage($orderTransaction): RedirectResponse
    {
        $order = $orderTransaction->getOrder();
        return new RedirectResponse(
            $this->asyncPaymentService
                ->urlService
                ->forwardToRoute(
                    'frontend.checkout.finish.page',
                    ['orderId' => $order->getId()]
                )
        );
    }

    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        return 'pay';
    }

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [];
    }
    /**
     * Get parameters common to all payment methods
     *
     * @param mixed $orderTransaction
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     * @param string|null $returnUrl
     *
     * @return array<mixed>
     */
    protected function getCommonRequestPayload(
        $orderTransaction,
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
            'amountDebit'   => $this->getOrderTotalWithFee(
                $order,
                $salesChannelId,
                $paymentCode
            ),
            'currency'      => $this->asyncPaymentService->getCurrency($order)->getIsoCode(),
            'returnURL'     => $returnUrl ?: $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag),
            'cancelURL'     => sprintf('%s&cancel=1', $returnUrl ?: $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag)),
            'pushURL'       => $this->asyncPaymentService
                ->urlService
                ->getReturnUrl('buckaroo.payment.push'),

            'additionalParameters' => [
                'orderTransactionId' => $orderTransaction->getId(),
                'orderId' => $order->getId(),
                'sw-context-token' => $salesChannelContext->getToken()
            ],

            'description' => $this->asyncPaymentService
                ->settingsService
                ->getParsedLabel($order, $salesChannelId, 'transactionLabel'),
            'clientIP' => $this->getIp(),
        ];
    }

    /**
     * Get order total, if a existing fee is on the order we remove it and add the new fee
     *
     * @param OrderEntity $order
     * @param string $salesChannelId
     * @param string $paymentCode
     *
     * @return float
     */
    private function getOrderTotalWithFee(
        OrderEntity $order,
        string $salesChannelId,
        string $paymentCode
    ): float {
        $fee =  $this->getFee($paymentCode, $salesChannelId);

        $existingFee = $order->getCustomFieldsValue('buckarooFee');

        if ($existingFee !== null && is_scalar($existingFee)) {
            $fee = $fee - (float)$existingFee;
        }

        return $order->getAmountTotal() + $fee;
    }

    /**
     * @return array<mixed>
     */
    private function getIp(): array
    {
        $request = Request::createFromGlobals();
        $remoteIp = $request->getClientIp();

        return [
            'address'       => $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }


    /**
     * Get fee for current payment
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return float
     */
    protected function getFee(string $paymentCode, string $salesChannelId): float
    {
        return $this->asyncPaymentService
            ->settingsService
            ->getBuckarooFee($paymentCode, $salesChannelId);
    }

    /**
     * Get buckaroo client
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return Client
     */
    private function getClient(string $paymentCode, string $salesChannelId, RequestDataBag $dataBag): Client
    {
        if (
            $paymentCode === 'paybybank' &&
            $dataBag->get('payBybankMethodId') === 'INGBNL2A' &&
            $this->asyncPaymentService->isMobile(Request::createFromGlobals())
        ) {
            $paymentCode = 'ideal';
        }
        return $this->asyncPaymentService
            ->clientService
            ->get($paymentCode, $salesChannelId);
    }

    /**
     * Get payment class
     *
     * @return AbstractPayment
     * @throws PaymentException
     */
    private function getPayment(string $transactionId): AbstractPayment
    {
        $paymentClass = null;

        if (class_exists($this->paymentClass)) {
            $paymentClass = new $this->paymentClass();
        }
        if ($paymentClass === null || !$paymentClass instanceof AbstractPayment) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Invalid buckaroo payment class'
            );
        }
        return $paymentClass;
    }
    /**
     * @param PaymentTransactionStruct $transaction
     * @param Request $request
     * @param Context $context
     * @throws PaymentException
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $orderTransaction = $this->asyncPaymentService->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext($context);
        
        $this->asyncPaymentService->paymentStateService->finalizePayment(
            $transaction,
            $request,
            $salesChannelContext
        );
    }

    /**
     * When you do payment in update order mode the request bag doesn't have all the request parameter
     * so we create a new bag with the current $_GET & $_POST parameters
     *
     * @param RequestDataBag $currentBag
     *
     * @return RequestDataBag $bag
     */
    protected function getRequestBag(RequestDataBag $currentBag)
    {
        if ($this->isUpdateOrder($currentBag)) {
            $request = new Request($_GET, $_POST);
            return new RequestDataBag(
                $request->request->all()
            );
        }
        return $currentBag;
    }
    protected function getDataBag(DataBag $currentBag): DataBag
    {
        if ($this->isUpdateOrder($currentBag)) {
            $request = new Request($_GET, $_POST);
            return new DataBag($request->request->all());
        }
        return $currentBag;
    }
    /**
     * Check if update order
     *
     * @param RequestDataBag $bag
     *
     * @return boolean
     */
    protected function isUpdateOrder(RequestDataBag $bag)
    {
        return $bag->has('errorUrl') &&
            is_scalar($bag->get('errorUrl')) &&
            strstr((string)$bag->get('errorUrl'), '/account/order/edit/') !== false;
    }

    /**
     * Get full return url
     *
     * @param mixed $orderTransaction
     * @param OrderEntity $order
     * @param DataBag $dataBag
     *
     * @return string
     */
    protected function getReturnUrl($orderTransaction, OrderEntity $order, $dataBag)
    {
        if ($dataBag->has('finishUrl') && is_scalar($dataBag->get('finishUrl'))) {
            $finishUrl = (string)$dataBag->get('finishUrl');
            if (
                strpos($finishUrl, 'http://') === 0 ||
                strpos($finishUrl, 'https://') === 0
            ) {
                return $finishUrl;
            }

            return rtrim(
                $this->asyncPaymentService
                    ->urlService
                    ->forwardToRoute('frontend.home.page', []),
                "/"
            ) . (string)$finishUrl;
        }

        return $this->getDefaultReturnUrl($orderTransaction, $order, $dataBag);
    }

    /**
     * Get default return URL
     *
     * @param mixed $orderTransaction
     * @param OrderEntity $order
     * @param DataBag $dataBag
     *
     * @return string
     */
    protected function getDefaultReturnUrl($orderTransaction, OrderEntity $order, $dataBag): string
    {
        return $this->asyncPaymentService
            ->urlService
            ->forwardToRoute(
                'frontend.checkout.finish.page',
                ['orderId' => $order->getId()]
            );
    }

    /**
     *
     * @param OrderEntity $order
     * @param string|null $paymentCode
     *
     * @return array<mixed>
     */
    protected function getOrderLinesArray(
        OrderEntity $order,
        string $paymentCode = null,
        ?Context $context = null
    ): array {
        return $this->asyncPaymentService
            ->formatRequestParamService
            ->getOrderLinesArray($order, $paymentCode, $context);
    }

    /**
     * Get settings from storage
     *
     * @param string $key
     * @param string|null $salesChannelId
     *
     * @return mixed
     */
    public function getSetting(string $key, string $salesChannelId = null)
    {
        return $this->asyncPaymentService
            ->settingsService
            ->getSetting($key, $salesChannelId);
    }

    private function setFeeOnOrder(
        $orderTransaction,
        $order,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $fee =  $this->getFee($paymentCode, $salesChannelContext->getSalesChannelId());

        $this->asyncPaymentService
            ->checkoutHelper
            ->applyFeeToOrder(
                $order->getId(),
                $fee,
                $salesChannelContext->getContext()
            );
    }

    /**
     * Check if afterpay old is enabled
     *
     * @param string $salesChannelContextId
     *
     * @return boolean
     */
    protected function isAfterpayOld(string $salesChannelContextId)
    {
        return $this->getSetting('afterpayEnabledold', $salesChannelContextId) === true;
    }
}
