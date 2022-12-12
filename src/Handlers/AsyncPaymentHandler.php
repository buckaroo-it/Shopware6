<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Exception;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Buckaroo\Payload\DataRequest;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Shopware\Core\Checkout\Checkout;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    protected AsyncPaymentService $asyncPaymentService;

    protected CheckoutHelper $checkoutHelper;

    public function __construct(AsyncPaymentService $asyncPaymentService)
    {
        $this->asyncPaymentService = $asyncPaymentService;
        $this->checkoutHelper = $asyncPaymentService->checkoutHelper;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $buckarooKey
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws AsyncPaymentProcessException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $buckarooKey = null,
        string $type = 'redirect',
        string $version = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $this->asyncPaymentService->logger->info(__METHOD__ . "|1|", [$_POST]);

        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $this->asyncPaymentService->client->setSalesChannelId($salesChannelId);

        $order = $transaction->getOrder();

        if (in_array($buckarooKey, ['klarnakp'])) {
            $request = new DataRequest;
        } else {
            $request = new TransactionRequest;
        }
        $request->setClientIPAndAgent(Request::createFromGlobals());
        $finalizePage = $this->getReturnUrl($transaction, $dataBag);

        if ($buckarooKey != 'RequestToPay') {
            $request->setDescription(
                $this->asyncPaymentService->settingsService->getParsedLabel($order, $salesChannelId, 'transactionLabel')
            );
        }

        $cancelUrl = sprintf('%s&cancel=1', $finalizePage);
        $request->setReturnURL($finalizePage);
        $request->setReturnURLCancel($cancelUrl);
        $request->setPushURL($this->asyncPaymentService->urlService->getReturnUrl('buckaroo.payment.push'));

        $request->setAdditionalParameter('orderTransactionId', $transaction->getOrderTransaction()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setAdditionalParameter('sw-context-token', $salesChannelContext->getToken());

        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $this->asyncPaymentService->checkoutHelper->getSession()->set('buckaroo_order_number', $order->getId());
        $request->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $request->setAmountDebit($order->getAmountTotal());

        $configKey = $buckarooKey;
        if ($buckarooKey === 'afterpaydigiaccept') {
            $configKey = 'afterpay';
        }
        if ($buckarooFee = $this->asyncPaymentService->checkoutHelper->getBuckarooFee($configKey . 'Fee', $salesChannelId)) {
            $this->asyncPaymentService->checkoutHelper->updateOrderCustomFields($order->getId(), ['buckarooFee' => $buckarooFee]);
            $request->setAmountDebit($order->getAmountTotal() + $buckarooFee);
        }

        $request->setServiceName($buckarooKey);
        $request->setServiceVersion($version);
        $request->setServiceAction('Pay');

        $this->payBefore($dataBag, $request, $salesChannelId);

        if ($buckarooKey == 'applepay') {
            $this->payApplePay($dataBag, $request);
        }

        if ($buckarooKey == 'capayable') {
            $request->setServiceAction('PayInInstallments');
            $request->setOrder(null);
        }

        if ($buckarooKey == 'klarnain') {
            $request->setServiceName('klarna');
            $request->setServiceAction('PayInInstallments');
            $request->setOrder(null);
        }

        if ($buckarooKey == 'creditcard' && $dataBag->get('creditcard')) {
            $request->setServiceName($dataBag->get('creditcard'));
        }

        if ($buckarooKey == 'creditcards' && $dataBag->get('creditcards_issuer') && $dataBag->get('encryptedCardData')) {
            $request->setServiceAction('PayEncrypted');
            $request->setServiceName($dataBag->get('creditcards_issuer'));
            $request->setServiceParameter('EncryptedCardData', $dataBag->get('encryptedCardData'));
        }

        if ($buckarooKey == 'payperemail') {
            $request->setServiceAction('PaymentInvitation');
            $request->setAdditionalParameter('fromPayPerEmail', 1);
        }

        if ($buckarooKey == 'paypal' && $dataBag->has('orderId')) {
            $request->setServiceParameter('PayPalOrderId', $dataBag->get('orderId'));
        }

        if ($buckarooKey == 'giftcards') {
            $list = 'ideal';
            $request->removeServices();
            if ($allowedgiftcards = $this->asyncPaymentService->settingsService->getSetting('allowedgiftcards', $salesChannelId)) {
                foreach ($allowedgiftcards as $key => $value) {
                    $list .= ',' . $value;
                }
            }
            $request->setServicesSelectableByClient($list);
            $request->setContinueOnIncomplete('RedirectToHTML');
        }

        if (!empty($gatewayInfo['additional']) && ($additional = $gatewayInfo['additional'])) {
            foreach ($additional as $item) {
                foreach ($item as $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], isset($value['Group']) ? $value['Group'] : null, isset($value['GroupID']) ? $value['GroupID'] : null);
                }
            }
        }

        try {
            if ($buckarooKey == 'klarnakp') {
                $url = $this->asyncPaymentService->urlService->getDataRequestUrl($buckarooKey, $salesChannelId);
            } else {
                $url = $this->asyncPaymentService->urlService->getTransactionUrl($configKey, $salesChannelId);
            }
            $locale = $this->asyncPaymentService->checkoutHelper->getSalesChannelLocaleCode($salesChannelContext);
            $response = $this->asyncPaymentService->client->post(
                $url,
                $request,
                TransactionResponse::class,
                $locale
            );
            $this->handlePayResponse($response, $order, $salesChannelContext);
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }

        if ($this->asyncPaymentService->settingsService->getSetting('stockReserve', $salesChannelId) && !$response->isCanceled()) {
            $this->asyncPaymentService->checkoutHelper->stockReserve($order);
        }

        $pendingPaymentStatus = ($this->asyncPaymentService->settingsService->getSetting('pendingPaymentStatus', $salesChannelId) && !$response->isCanceled()) ? $this->asyncPaymentService->settingsService->getSetting('pendingPaymentStatus', $salesChannelContext->getSalesChannelId()) : 'open';

        $context = $salesChannelContext->getContext();

        $this->asyncPaymentService->checkoutHelper->appendCustomFields(
            $order->getId(),
            [
                'buckaroo_payment_in_test_mode' => $response->isTestMode(),
                'buckaroo_cancel_url' => $cancelUrl
            ]
        );
        if ($response->hasRedirect()) {
            if ($response->isAwaitingConsumer() || $response->isPendingProcessing() || $response->isWaitingOnUserInput()) {
                $this->asyncPaymentService->stateTransitionService->transitionPaymentState($pendingPaymentStatus, $transaction->getOrderTransaction()->getId(), $context);
            }
            return new RedirectResponse($response->getRedirectUrl());
        } elseif ($response->isSuccess() || $response->isAwaitingConsumer() || $response->isPendingProcessing() || $response->isWaitingOnUserInput()) {
            if (!$response->isSuccess()) {
                $this->asyncPaymentService->stateTransitionService->transitionPaymentState($pendingPaymentStatus, $transaction->getOrderTransaction()->getId(), $context);
            }
            return new RedirectResponse($this->asyncPaymentService->urlService->forwardToRoute('frontend.checkout.finish.page', ['orderId' => $order->getId()]));
        } elseif ($response->isCanceled()) {
            throw new CustomerCanceledAsyncPaymentException(
                $transaction->getOrderTransaction()->getId(),
                ''
            );
        }

        return new RedirectResponse($finalizePage . '?orderId=' . $order->getId() . '&error=' . base64_encode($response->getSubCodeMessage()));
    }

    protected function payBefore(
        RequestDataBag $dataBag,
        \Buckaroo\Shopware6\Buckaroo\Payload\Request $request,
        $salesChannelId
    ): void {
        $this->asyncPaymentService->logger->info(__METHOD__ . "|1|");
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
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

    private function payApplePay(RequestDataBag $dataBag, $request)
    {
        if ($applePayInfo = $dataBag->get('applePayInfo')) {
            if (
                ($applePayInfo = json_decode($applePayInfo))
                &&
                !empty($applePayInfo->billingContact)
                &&
                !empty($applePayInfo->token)
            ) {
                if (!empty($applePayInfo->billingContact->givenName) && !empty($applePayInfo->billingContact->familyName)) {
                    $request->setServiceParameter(
                        'CustomerCardName',
                        $applePayInfo->billingContact->givenName . ' ' . $applePayInfo->billingContact->familyName
                    );
                }

                $request->setServiceParameter('PaymentData', base64_encode(json_encode($applePayInfo->token)));
            }
        }
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
        return $bag->has('errorUrl') && strstr($bag->get('errorUrl'), '/account/order/edit/') !== false;
    }

    /**
     * Handle pay response from buckaroo
     *
     * @param TransactionResponse $response
     * @param OrderEntity $order
     * @param SalesChannelContext $saleChannelContext
     *
     * @return void
     */
    protected function handlePayResponse(
        TransactionResponse $response,
        OrderEntity $order,
        SalesChannelContext $saleChannelContext
    ): void {
        return;
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
            if (
                strpos($dataBag->get('finishUrl'), 'http://') === 0 ||
                strpos($dataBag->get('finishUrl'), 'https://') === 0
            ) {
                return $dataBag->get('finishUrl');
            }
            return rtrim($this->asyncPaymentService->urlService->forwardToRoute('frontend.home.page', []), "/") . (string)$dataBag->get('finishUrl');
        }
        if (
            version_compare(
                $this->asyncPaymentService->checkoutHelper->getShopwareVersion(),
                '6.4.2.0',
                '<'
            )
            && strpos("buckaroo", $transaction->getReturnUrl()) === false
        ) {
            return str_replace("/payment", "/buckaroo/payment", $transaction->getReturnUrl());
        }

        return $transaction->getReturnUrl();
    }
    protected function getOrderLinesArray(OrderEntity $order)
    {
        return $this->asyncPaymentService->formatRequestParamService->getOrderLinesArray($order);
    }
    public function getRequestParameterRow($value, $name, $groupType = null, $groupId = null)
    {
        return $this->asyncPaymentService
            ->formatRequestParamService
            ->getRequestParameterRow($value, $name, $groupType, $groupId);
    }
}
