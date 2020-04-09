<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Handlers;

use Exception;
use Buckaroo\Shopware6\Helper\ApiHelper;
use Buckaroo\Shopware6\Helper\CheckoutHelper;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Helper\BkrHelper;
use Buckaroo\Shopware6\Helper\UrlHelper;
use Buckaroo\Shopware6\API\Payload\TransactionRequest;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var ApiHelper $apiHelper */
    public $apiHelper;
    /** @var CheckoutHelper $checkoutHelper */
    public $checkoutHelper;
    /** @var BkrHelper $bkrHelper */
    public $bkrHelper;

    /**
     * Buckaroo constructor.
     * @param ApiHelper $apiHelper
     * @param CheckoutHelper $checkoutHelper
     * @param BkrHelper $bkrHelper
     */
    public function __construct(
        ApiHelper $apiHelper,
        CheckoutHelper $checkoutHelper,
        BkrHelper $bkrHelper
    ) {
        $this->apiHelper = $apiHelper;
        $this->checkoutHelper = $checkoutHelper;
        $this->bkrHelper = $bkrHelper;
    }

    /**
     * Get the base url
     * When the environment is set live, but the payment is set as test, the test url will be used
     *
     * @return string Base-url
     */
    protected function getBaseUrl($method = ''):string
    {
        return $this->apiHelper->getEnvironment($method) == 'live' ? UrlHelper::LIVE : UrlHelper::TEST;
    }

    /**
     * @return string Full transaction url
     */
    protected function getTransactionUrl($method = ''):string
    {
        return rtrim($this->getBaseUrl($method), '/') . '/' . ltrim('json/Transaction', '/');
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
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
        string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {

        $bkrClient = $this->apiHelper->initializeBuckarooClient();

        $order = $transaction->getOrder();
        $customer = $salesChannelContext->getCustomer();
        $request = $this->bkrHelper->getGlobals();

        $request = new TransactionRequest;

        $request->setDescription('Payment for order #' . $order->getOrderNumber());
        
        $request->setReturnURL($this->checkoutHelper->getReturnUrl('buckaroo.payment.finalize'));
        $request->setReturnURLCancel(sprintf('%s?cancel=1', $this->checkoutHelper->getReturnUrl('buckaroo.payment.finalize')));
        $request->setPushURL($this->checkoutHelper->getReturnUrl('buckaroo.payment.push'));
        // $request->setPushURL($transaction->getReturnUrl());
        
        $request->setAdditionalParameter('orderTransactionId', $transaction->getOrderTransaction()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());

        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($salesChannelContext->getCurrency()->getIsoCode());
        $request->setAmountDebit($order->getAmountTotal());

        $request->setServiceName($gatewayInfo['key']);
        $request->setServiceVersion($gatewayInfo['version']);
        $request->setServiceAction('Pay');

        if($issuer_id = $gatewayInfo['issuer_id']){
            $request->setServiceParameter('issuer', $issuer_id);
        }

        if($card = $gatewayInfo['creditcard']){
            $request->setServiceName($card);
        }

        if($additional = $gatewayInfo['additional']){
            foreach ($additional as $key2 => $item) {
                foreach ($item as $key => $value) {
                    $request->setServiceParameter($value['Name'], $value['_'], $value['Group'], $value['GroupID']);
                }
            }
        }

        try {
            $url = $this->getTransactionUrl($gatewayInfo['key']);
            $response = $bkrClient->post($url, $request, 'Buckaroo\Shopware6\API\Payload\TransactionResponse');
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }
        
        if($response->isSuccess()){
            return new RedirectResponse('/checkout/finish?orderId=' . $order->getId());
        }elseif($response->hasRedirect()) {
            return new RedirectResponse($response->getRedirectUrl());
        }elseif($response->isCanceled()){
            throw new CustomerCanceledAsyncPaymentException(
            $transaction->getOrderTransaction()->getId(),
            ''
            );
        }

        throw new AsyncPaymentFinalizeException(
            $transaction->getOrderTransaction()->getId(),
            $response->getSubCodeMessage()
        );

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
}
