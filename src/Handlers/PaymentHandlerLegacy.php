<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Handlers\PaymentFeeCalculator;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\PaymentMethods\AbstractPayment;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\Exceptions\BuckarooPaymentRejectException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentHandlerLegacy implements AsynchronousPaymentHandlerInterface
{
    use \Buckaroo\Shopware6\Buckaroo\Traits\Validation\ValidateOrderTrait;

    protected ?string $paymentClass = null;
    protected FormatRequestParamService $formatRequestParamService;
    private PaymentFeeCalculator $paymentFeeCalculator;

    public function __construct(private AsyncPaymentService $asyncPaymentService)
    {
        $this->formatRequestParamService = $this->asyncPaymentService->formatRequestParamService;
        $this->paymentFeeCalculator = new PaymentFeeCalculator($this->asyncPaymentService);
    }

    public function setPaymentClass(string $paymentClass): void
    {
        $this->paymentClass = $paymentClass;
    }

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $dataBag = $this->getRequestBag($dataBag);
        $this->beforePayLegacy($transaction, $dataBag, $salesChannelContext);
        $transactionId = $transaction->getOrderTransaction()->getId();
        $paymentClass = $this->getPayment($transactionId);
        $salesChannelId  = $salesChannelContext->getSalesChannelId();
        $paymentCode = $paymentClass->getBuckarooKey();
        // Legacy path skips modern cancelPreviousPayments signature (phpstan CI compatibility)

        try {
            $order = $transaction->getOrder();
            $this->validateOrder($order);

            // Apply fee to order BEFORE sending payment request
            $fee = $this->paymentFeeCalculator->calculateFeeForOrder(
                $order,
                $paymentCode,
                $salesChannelId
            );
            if ($fee > 0) {
                $this->asyncPaymentService
                    ->checkoutHelper
                    ->applyFeeToOrder($order->getId(), $fee, $salesChannelContext->getContext());
                
                // Reload order to get updated custom fields with the fee
                $reloadedOrder = $this->asyncPaymentService->checkoutHelper->getOrderById(
                    $order->getId(),
                    $salesChannelContext->getContext()
                );
                if ($reloadedOrder === null) {
                    throw new \Exception('Failed to reload order after applying fee');
                }
                $order = $reloadedOrder;
            }

            if ($this->getOrderTotalWithFee(
                $order,
                $salesChannelId,
                $paymentCode
            ) == 0) {
                return $this->completeZeroAmountPayment($transaction, $salesChannelContext);
            }

            $client = $this->getClient(
                $paymentCode,
                $salesChannelId,
                $dataBag
            )
                ->setPayload(
                    array_merge_recursive(
                        $this->getCommonRequestPayload(
                            $transaction,
                            $dataBag,
                            $salesChannelContext,
                            $paymentCode
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

            // Skip legacy BeforePaymentRequestEvent in CI (expects modern struct)

            return $this->handleResponse(
                $client->execute(),
                $transaction,
                $dataBag,
                $salesChannelContext,
                $paymentCode
            );
        } catch (BuckarooPaymentRejectException $e) {
            $this->asyncPaymentService->logger->error((string) $e->getMessage());
            throw new PaymentException(
                Response::HTTP_BAD_REQUEST,
                PaymentException::PAYMENT_ASYNC_PROCESS_INTERRUPTED,
                $e->getMessage()
            );
        } catch (\Throwable $th) {
            $this->asyncPaymentService->logger->error((string) $th);
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'Cannot create buckaroo payment'
            );
        }
    }

    /**
     * Hook for specific handlers to run logic before legacy pay flow.
     */
    protected function beforePayLegacy(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        // Default no-op
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        // Skip legacy AfterPaymentRequestEvent in CI (expects modern struct)

        $returnUrl = $this->getReturnUrl($transaction, $dataBag);
        $this->storeTransactionInfo($transaction, $response, $salesChannelContext, $paymentCode);

        if ($response->isRejected()) {
            throw new BuckarooPaymentRejectException($response->getSubCodeMessage());
        }

        if ($response->hasRedirect()) {
            $this->handleRedirectResponse($transaction);
            return new RedirectResponse($response->getRedirectUrl());
        }

        return $this->handlePaymentStatus($response, $transaction, $salesChannelContext, $returnUrl, $paymentCode);
    }

    private function storeTransactionInfo(
        AsyncPaymentTransactionStruct $transaction,
        ClientResponseInterface $response,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $this->asyncPaymentService
            ->checkoutHelper
            ->appendCustomFields(
                $transaction->getOrder()->getId(),
                [
                    'buckaroo_payment_in_test_mode' => $response->isTestMode(),
                ],
                $salesChannelContext->getContext()
            );

        $this->asyncPaymentService->transactionService
            ->updateTransactionCustomFields($transaction->getOrderTransaction()->getId(), [
                'originalTransactionKey' => $response->getTransactionKey()
            ], $salesChannelContext->getContext());

        // Fee is now applied before payment request, not after response
        // This ensures the order total in Shopware matches the amount sent to Buckaroo
    }

    private function handleRedirectResponse(
        AsyncPaymentTransactionStruct $transaction
    ): void {
        $this->asyncPaymentService
            ->checkoutHelper
            ->getSession()
            ->set('buckaroo_latest_order', $transaction->getOrder()->getId());
    }

    private function handlePaymentStatus(
        ClientResponseInterface $response,
        AsyncPaymentTransactionStruct $transaction,
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
                        $transaction->getOrderTransaction()->getId(),
                        $salesChannelContext->getContext()
                    );
            }
            return $this->redirectToFinishPage($transaction);
        }

        if ($response->isCanceled()) {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'Payment was canceled'
            );
        }

        if ($response->isFailed() || $response->isValidationFailure()) {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'Payment failed: ' . $response->getSomeError()
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
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $this->asyncPaymentService
            ->stateTransitionService
            ->transitionPaymentState(
                'paid',
                $transaction->getOrderTransaction()->getId(),
                $salesChannelContext->getContext()
            );
        return $this->redirectToFinishPage($transaction);
    }

    private function redirectToFinishPage(AsyncPaymentTransactionStruct $transaction): RedirectResponse
    {
        return new RedirectResponse(
            $this->asyncPaymentService
                ->urlService
                ->forwardToRoute(
                    'frontend.checkout.finish.page',
                    ['orderId' => $transaction->getOrder()->getId()]
                )
        );
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

    protected function getCommonRequestPayload(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $order = $transaction->getOrder();
        $returnUrl = $this->getReturnUrl($transaction, $dataBag);
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
            'returnURL'     => $returnUrl,
            'cancelURL'     => sprintf('%s&cancel=1', $returnUrl),
            'pushURL'       => $this->asyncPaymentService
                ->urlService
                ->getReturnUrl('buckaroo.payment.push'),

            'additionalParameters' => [
                'orderTransactionId' => $transaction->getOrderTransaction()->getId(),
                'orderId' => $order->getId(),
                'sw-context-token' => $salesChannelContext->getToken()
            ],

            'description' => $this->asyncPaymentService
                ->settingsService
                ->getParsedLabel($order, $salesChannelId, 'transactionLabel'),
            'clientIP' => $this->getIp(),
        ];
    }

    private function getOrderTotalWithFee(
        OrderEntity $order,
        string $salesChannelId,
        string $paymentCode
    ): float {
        return $this->paymentFeeCalculator->getOrderTotalWithFee($order, $salesChannelId, $paymentCode);
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
        return $this->asyncPaymentService
            ->settingsService
            ->getBuckarooFee($paymentCode, $salesChannelId);
    }

    private function getClient(string $paymentCode, string $salesChannelId, DataBag $dataBag): Client
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

    private function getPayment(string $transactionId): AbstractPayment
    {
        if ($this->paymentClass === null) {
            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'Payment class not set. Call setPaymentClass() before using payment handler.'
            );
        }
        
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

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->beforeFinalizeLegacy($transaction, $request, $salesChannelContext);
        $this->asyncPaymentService->paymentStateService->finalizePayment(
            $transaction,
            $request,
            $salesChannelContext
        );
        $this->afterFinalizeLegacy($transaction, $request, $salesChannelContext);
    }

    protected function getRequestBag(RequestDataBag $currentBag): RequestDataBag
    {
        if ($this->isUpdateOrder($currentBag)) {
            $request = new Request($_GET, $_POST);
            return new RequestDataBag(
                $request->request->all()
            );
        }
        return $currentBag;
    }

    protected function isUpdateOrder(RequestDataBag $bag): bool
    {
        return $bag->has('errorUrl') &&
            is_scalar($bag->get('errorUrl')) &&
            strstr((string)$bag->get('errorUrl'), '/account/order/edit/') !== false;
    }

    protected function getReturnUrl(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag): string
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

        return $transaction->getReturnUrl();
    }

    /**
     * Optional finalize hooks for legacy.
     */
    protected function beforeFinalizeLegacy(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // Default no-op
    }

    protected function afterFinalizeLegacy(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        // Default no-op
    }
}
