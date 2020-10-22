<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Buckaroo\Payload\DataRequest;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Helper;
use Exception;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var Helper $helper */
    public $helper;
    /** @var CheckoutHelper $checkoutHelper */
    public $checkoutHelper;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Buckaroo constructor.
     * @param Helper $helper
     * @param CheckoutHelper $checkoutHelper
     */
    public function __construct(
        Helper $helper,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger
    ) {

        $this->helper         = $helper;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
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
    ): RedirectResponse{

        $bkrClient = $this->helper->initializeBkr();

        $order = $transaction->getOrder();

        if ($buckarooKey == 'klarnakp') {
            $request = new DataRequest;
        } else {
            $request = new TransactionRequest;
        }

        $finalizePage = $this->checkoutHelper->getReturnUrl('buckaroo.payment.finalize');

        if ($buckarooKey != 'RequestToPay') {
            $request->setDescription($this->checkoutHelper->getTranslate('buckaroo.order.paymentDescription', ['orderNumber' => $order->getOrderNumber()]));
        }

        $request->setReturnURL($finalizePage);
        $request->setReturnURLCancel(sprintf('%s?cancel=1', $finalizePage));
        $request->setPushURL($this->checkoutHelper->getReturnUrl('buckaroo.payment.push'));

        $request->setAdditionalParameter('orderTransactionId', $transaction->getOrderTransaction()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setAdditionalParameter('sw-context-token', $salesChannelContext->getToken());

        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $request->setAmountDebit($order->getAmountTotal());

        if($buckarooFee = $this->checkoutHelper->getBuckarooFee($buckarooKey.'Fee')) {
            $this->checkoutHelper->updateOrderCustomFields($order->getId(),['buckarooFee' => $buckarooFee]);
            $request->setAmountDebit($order->getAmountTotal() + $buckarooFee);
        }

        $request->setServiceName($buckarooKey);
        $request->setServiceVersion($version);
        $request->setServiceAction('Pay');

        $this->payBefore($dataBag, $request);

        if ($buckarooKey == 'applepay') {
            $this->payApplePay($dataBag, $request);
        }

        if ($buckarooKey == 'capayable') {
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

        if ($buckarooKey == 'giftcards') {
            $list = 'ideal';
            $request->removeServices();
            if($allowedgiftcards = $this->helper->getSettingsValue('allowedgiftcards')){
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
                    $request->setServiceParameter($value['Name'], $value['_'], isset($value['Group'])?$value['Group']:null, isset($value['GroupID'])?$value['GroupID']:null);
                }
            }
        }

        try {
            if ($buckarooKey == 'klarnakp') {
                $url = $this->checkoutHelper->getDataRequestUrl($buckarooKey);
                $response = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');
            } else {
                $url = $this->checkoutHelper->getTransactionUrl($buckarooKey);
                $response = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');
            }
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }

        if($this->helper->getSettingsValue('stockReserve') && !$response->isCanceled()){
            $this->checkoutHelper->stockReserve($order);
        }

        $pendingPaymentStatus = ($this->helper->getSettingsValue('pendingPaymentStatus') && !$response->isCanceled()) ? $this->helper->getSettingsValue('pendingPaymentStatus') : 'open';

        $context = $salesChannelContext->getContext();
        if ($response->hasRedirect()) {
            if($response->isAwaitingConsumer() || $response->isPendingProcessing() || $response->isWaitingOnUserInput()){
                $this->checkoutHelper->transitionPaymentState($pendingPaymentStatus, $transaction->getOrderTransaction()->getId(), $context);
            }
            return new RedirectResponse($response->getRedirectUrl());
        } elseif ($response->isSuccess() || $response->isAwaitingConsumer() || $response->isPendingProcessing() || $response->isWaitingOnUserInput()) {
            if(!$response->isSuccess()){
                $this->checkoutHelper->transitionPaymentState($pendingPaymentStatus, $transaction->getOrderTransaction()->getId(), $context);
            }
            return new RedirectResponse($this->generateUrl('frontend.checkout.finish.page', ['orderId' => $order->getId()]));
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
        \Buckaroo\Shopware6\Buckaroo\Payload\Request $request
    ): void {
        $this->logger->info(__METHOD__ . "|1|");
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    /**
     *
     * @Route("/example/route", name="example.route", defaults={"csrf_protected"=false}, methods={"POST"})
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {

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
                    $request->setServiceParameter('CustomerCardName',
                        $applePayInfo->billingContact->givenName . ' ' . $applePayInfo->billingContact->familyName
                    );
                }

                $request->setServiceParameter('PaymentData', base64_encode(json_encode($applePayInfo->token)));
            }
        }
    }
}
