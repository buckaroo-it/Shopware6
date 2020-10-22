<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Helper;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;

/**
 */
class PushController extends StorefrontController
{
    /**
     * @var LoggerInterface
     */
    private $logger;
    
    private $transactionRepository;
    
    private $checkoutHelper;
    
    private $orderRepository;

    public function __construct(
        EntityRepositoryInterface $transactionRepository,
        CheckoutHelper $checkoutHelper,
        EntityRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->checkoutHelper        = $checkoutHelper;
        $this->orderRepository       = $orderRepository;
        $this->logger                = $logger;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/buckaroo/push", name="buckaroo.payment.push", defaults={"csrf_protected"=false}, methods={"POST"})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function pushBuckaroo(Request $request, SalesChannelContext $salesChannelContext)
    {
        $status             = $request->request->get('brq_statuscode');
        $context            = $salesChannelContext->getContext();
        $brqAmount          = $request->request->get('brq_amount');
        $brqOrderId         = $request->request->get('ADD_orderId');
        $brqAmountCredit    = $request->request->get('brq_amount_credit');
        $brqInvoicenumber   = $request->request->get('brq_invoicenumber');
        $orderTransactionId = $request->request->get('ADD_orderTransactionId');
        $brqTransactionType = $request->request->get('brq_transaction_type');
        $paymentMethod      = $request->request->get('brq_primary_service');

        $validSignature = $this->checkoutHelper->validateSignature();
        if (!$validSignature) {
            return $this->json(['status' => false, 'message' => 'Signature from push is incorrect']);
        }

        if ($brqTransactionType != ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_GROUP_TRANSACTION) {
            $this->checkoutHelper->saveBuckarooTransaction($request, $context);
        }

        $transaction = $this->checkoutHelper->getOrderTransaction($orderTransactionId, $context);

        $order = $this->checkoutHelper->getOrderById($brqOrderId, $context);
        $totalPrice  = $order->getPrice()->getTotalPrice();

        //Check if the push is a refund request or cancel authorize
        if (isset($brqAmountCredit)) {
            if ($status != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS && $brqTransactionType == ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_CANCEL) {
                $currentStateId = $transaction->getStateId();
                return $this->json(['status' => true, 'message' => "Payment cancelled"]);
            }

            $status = ($brqAmountCredit < $totalPrice) ? 'partial_refunded' : 'refunded';
            $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, [$status => 1]);

            $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);

            return $this->json(['status' => true, 'message' => "Refund successful"]);
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            try {
                if($this->checkoutHelper->isOrderState(['cancelled'], $brqOrderId, $context)){
                    $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, 'reopen');
                }

                if($this->checkoutHelper->isTransitionPaymentState(['refunded','partial_refunded'], $orderTransactionId, $context)){
                    return $this->json(['status' => true, 'message' => "Payment state was updated earlier"]);
                }

                $paymentSuccesStatus = $this->checkoutHelper->getSettingsValue('paymentSuccesStatus') ? $this->checkoutHelper->getSettingsValue('paymentSuccesStatus') : "completed";
                $paymentState = (round($brqAmount, 2) == round($totalPrice, 2)) ? $paymentSuccesStatus : "pay_partially";
                $data = [];
                if ($paymentMethod && (strtolower($paymentMethod) == 'klarnakp')) {
                    $paymentState = 'do_pay';
                    $data['reservationNumber'] = $request->request->get('brq_SERVICE_klarnakp_ReservationNumber');
                }

                $this->checkoutHelper->transitionPaymentState($paymentState, $orderTransactionId, $context);
                $data = array_merge($data, [
                    'originalTransactionKey' => $request->request->get('brq_transactions'),
                    'brqPaymentMethod'       => $paymentMethod ? $paymentMethod : $request->request->get('brq_transaction_method'),
                ]);
                $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, $data);

                if($orderStatus = $this->checkoutHelper->getSettingsValue('orderStatus')){
                    if($orderStatus == 'complete'){
                        $this->checkoutHelper->changeOrderStatus($brqOrderId, $context,'process');
                    }
                    $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, $orderStatus);
                }

                if (!$this->checkoutHelper->isInvoiced($brqOrderId, $context)) {
                    if (round($brqAmount, 2) == round($totalPrice, 2)) {
                        $this->checkoutHelper->generateInvoice($brqOrderId, $context, $brqInvoicenumber);
                    }
                }
            } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
                 | StateMachineStateNotFoundException $exception) {
                throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
            }
            return $this->json(['status' => true, 'message' => "Payment state was updated"]);
        }

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR, ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER, ResponseStatus::BUCKAROO_STATUSCODE_FAILED, ResponseStatus::BUCKAROO_STATUSCODE_REJECTED])) {

            $paymentFailedStatus = $this->checkoutHelper->getSettingsValue('paymentFailedStatus') ? $this->checkoutHelper->getSettingsValue('paymentFailedStatus') : "cancelled";

            $this->checkoutHelper->transitionPaymentState($paymentFailedStatus, $orderTransactionId, $context);
            $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, 'cancel');

            return $this->json(['status' => true, 'message' => "Order cancelled"]);
        }

        return $this->json(['status' => false, 'message' => "Payment error"]);
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/buckaroo/finalize", name="buckaroo.payment.finalize", defaults={"csrf_protected"=false}, methods={"POST","GET"})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function finalizeBuckaroo(Request $request, SalesChannelContext $salesChannelContext)
    {
        $status        = $request->request->get('brq_statuscode');
        $statusMessage = $request->request->get('brq_statusmessage');
        $orderId       = $request->request->get('ADD_orderId');

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING])) {
            return $this->renderStorefront('@Storefront/storefront/buckaroo/page/finalize/_page2.html.twig', [
                'messages'     => [['type' => 'success', 'text' => $this->trans('buckaroo.messages.return791')]]
            ]);
        }

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS, ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS])) {
            if($token  = $request->request->get('ADD_sw-context-token')){
                $this->get('session')->set('sw-context-token', $token);
            }

            return new RedirectResponse($this->generateUrl('frontend.checkout.finish.page', ['orderId' => $request->request->get('ADD_orderId')]));
        }

        if ($request->query->getBoolean('cancel')) {
            $messages[] = ['type' => 'warning', 'text' => $this->trans('According to our system, you have canceled the payment. If this is not the case, please contact us.')];
        }

        if ($error = $request->query->filter('error')) {
            $messages[] = ['type' => 'danger', 'text' => base64_decode($error)];
        }

        if ($statusMessage=='Failed' && in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_FAILED])) {
            $messages[] = ['type' => 'danger', 'text' => 'Card number or pin is incorrect'];
        }

        if (empty($messages)) {
            $messages[] = ['type' => 'danger', 'text' => $statusMessage ? $statusMessage : $this->trans('Unfortunately an error occurred while processing your payment. Please try again. If this error persists, please choose a different payment method.')];
        }

        if (!$orderId && $orderId = $request->query->filter('orderId')) {}

        $lineItems = [];
        if ($orderId) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId))
                ->addAssociation('lineItems')
                ->addAssociation('lineItems.cover');
            /** @var OrderEntity|null $order */
            $order     = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();
            $lineItems = $order->getNestedLineItems();

            foreach ($messages as $message) {
                $this->addFlash($message['type'], $message['text']);
            }

        }

        return $this->renderStorefront('@Storefront/storefront/buckaroo/page/finalize/_page.html.twig', [
            'messages'     => $messages,
            'orderDetails' => $lineItems,
        ]);

    }

}
