<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

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
    private $logger;

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
        $this->logger->info(__METHOD__ . "|1|");

        $bkrClient = $this->helper->initializeBkr();

        $order = $transaction->getOrder();

        $request = new TransactionRequest;

        $finalizePage = $this->checkoutHelper->getReturnUrl('buckaroo.payment.finalize');

        $request->setDescription($this->checkoutHelper->getTranslate('buckaroo.order.paymentDescription', ['orderNumber' => $order->getOrderNumber()]));

        $request->setReturnURL($finalizePage);
        $request->setReturnURLCancel(sprintf('%s?cancel=1', $finalizePage));
        $request->setPushURL($this->checkoutHelper->getReturnUrl('buckaroo.payment.push'));

        $request->setAdditionalParameter('orderTransactionId', $transaction->getOrderTransaction()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $request->setAmountDebit($order->getAmountTotal());

        $request->setServiceName($buckarooKey);
        $request->setServiceVersion($version);
        $request->setServiceAction('Pay');

        if ($buckarooKey == 'applepay') {
            $this->payApplePay($dataBag, $request);
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
            $allowedgiftcards = $this->helper->getSettingsValue('allowedgiftcards');
            foreach ($allowedgiftcards as $key => $value) {
                $list .= ',' . $value;
            }
            $request->setServicesSelectableByClient($list);
            $request->setContinueOnIncomplete('RedirectToHTML');
        }

        if (!empty($gatewayInfo['additional']) && ($additional = $gatewayInfo['additional'])) {
            foreach ($additional as $item) {
                foreach ($item as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        try {
            $url      = $this->checkoutHelper->getTransactionUrl($buckarooKey);
            $response = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }

        if($this->helper->getSettingsValue('stockReserve') && !$response->isCanceled()){
            $this->checkoutHelper->stockReserve($order);
        }

        if ($response->hasRedirect()) {
            return new RedirectResponse($response->getRedirectUrl());
        } elseif ($response->isSuccess() || $response->isAwaitingConsumer() || $response->isPendingProcessing()) {
            return new RedirectResponse('/checkout/finish?orderId=' . $order->getId());
        } elseif ($response->isCanceled()) {
            throw new CustomerCanceledAsyncPaymentException(
                $transaction->getOrderTransaction()->getId(),
                ''
            );
        }

        return new RedirectResponse($finalizePage . '?orderId=' . $order->getId() . '&error=' . base64_encode($response->getSubCodeMessage()));

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
