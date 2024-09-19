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
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Buckaroo\Traits\Validation\ValidateOrderTrait;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Framework\Context;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws PaymentException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $dataBag = $this->getRequestBag($dataBag);
        $transactionId = $transaction->getOrderTransaction()->getId();
        $paymentClass = $this->getPayment($transactionId);
        $salesChannelId  = $salesChannelContext->getSalesChannelId();
        $paymentCode = $paymentClass->getBuckarooKey();
        $this->asyncPaymentService->cancelPreviousPayments($transaction);

        try {
            $order = $transaction->getOrder();
            $this->validateOrder($order);

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

            if (
                $paymentCode === "afterpay" &&
                !$this->isAfterpayOld($salesChannelContext->getSalesChannelId())
            ) {
                $client->setServiceVersion(2);
            }

            $this->asyncPaymentService->dispatchEvent(
                new BeforePaymentRequestEvent(
                    $transaction,
                    $dataBag,
                    $salesChannelContext,
                    $client
                )
            );

            return $this->handleResponse(
                $client->execute(),
                $transaction,
                $dataBag,
                $salesChannelContext,
                $paymentCode
            );
        } catch (\Throwable $th) {
            $this->asyncPaymentService->logger->error((string) $th);
            
            if (\Composer\InstalledVersions::getVersion('shopware/core') < 6.6) {
                throw PaymentException::asyncProcessInterrupted(
                    $transaction->getOrderTransaction()->getId(),
                    'Cannot create buckaroo payment'
                );
            }

            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'Cannot create buckaroo payment',
                $th
            );
        }
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $this->asyncPaymentService->dispatchEvent(
            new AfterPaymentRequestEvent(
                $transaction,
                $dataBag,
                $salesChannelContext,
                $response,
                $paymentCode
            )
        );

        $returnUrl = $this->getReturnUrl($transaction, $dataBag);

        $this->asyncPaymentService
            ->checkoutHelper
            ->appendCustomFields(
                $transaction->getOrder()->getId(),
                [
                    'buckaroo_payment_in_test_mode' => $response->isTestMode(),
                ],
                $salesChannelContext->getContext()
            );
        $this->setFeeOnOrder($transaction, $salesChannelContext, $paymentCode);
        if ($response->hasRedirect()) {
            $this->asyncPaymentService
                ->checkoutHelper
                ->getSession()
                ->set('buckaroo_latest_order', $transaction->getOrder()->getId());

            return new RedirectResponse($response->getRedirectUrl());
        }

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
                'Cannot create buckaroo payment'
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
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
    private function getClient(string $paymentCode, string $salesChannelId, DataBag $dataBag): Client
    {
        //do a ideal payment if the issuer is ING for payByBank on mobile devices
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws PaymentException
     * @throws PaymentFailedException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
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
     * @param DataBag $dataBag
     * @param AsyncPaymentTransactionStruct $transaction
     *
     * @return string
     */
    protected function getReturnUrl(AsyncPaymentTransactionStruct $transaction, $dataBag)
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
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): void {
        $fee =  $this->getFee($paymentCode, $salesChannelContext->getSalesChannelId());

        $this->asyncPaymentService
            ->checkoutHelper
            ->applyFeeToOrder(
                $transaction->getOrder()->getId(),
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
