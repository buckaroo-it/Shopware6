<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PushController extends StorefrontController
{
    
    private LoggerInterface $logger;

    private CheckoutHelper $checkoutHelper;

    private EntityRepository $orderRepository;

    public function __construct(
        CheckoutHelper $checkoutHelper,
        EntityRepository $orderRepository,
        LoggerInterface $logger
    ) {
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
        $this->logger->info(__METHOD__ . "|1|", [$_POST]);

        $status             = $request->request->get('brq_statuscode');
        $context            = $salesChannelContext->getContext();
        $brqAmount          = (float)$request->request->get('brq_amount');
        $brqOrderId         = $request->request->get('ADD_orderId');
        $brqAmountCredit    = $request->request->get('brq_amount_credit');
        $brqInvoicenumber   = $request->request->get('brq_invoicenumber');
        $orderTransactionId = $request->request->get('ADD_orderTransactionId');
        $brqTransactionType = $request->request->get('brq_transaction_type');
        $paymentMethod      = $request->request->get('brq_primary_service');
        $mutationType       = $request->request->get('brq_mutationtype');
        $brqPaymentMethod   = $request->request->get('brq_transaction_method');
        $originalTransactionKey   = $request->request->get('brq_transactions');

        if (!$this->checkoutHelper->validateSignature($salesChannelContext->getSalesChannelId())) {
            $this->logger->info(__METHOD__ . "|5|");
            return $this->json(['status' => false, 'message' => $this->trans('buckaroo.messages.signatureIncorrect')]);
        }

        //skip mutationType Informational
        if ($mutationType == ResponseStatus::BUCKAROO_MUTATION_TYPE_INFORMATIONAL) {
            $this->logger->info(__METHOD__ . "|5.1|");
            $data = [
                'originalTransactionKey' => $originalTransactionKey,
                'brqPaymentMethod'       => $brqPaymentMethod,
                'brqInvoicenumber'       => $brqInvoicenumber,
            ];
            $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, $data);

            return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.skipInformational')]);
        }

        if (
            !empty($request->request->get('brq_transaction_method'))
            && ($request->request->get('brq_transaction_method') === 'paypal')
            && ($status == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        ) {
            $status = ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
        }

        $order = $this->checkoutHelper->getOrderById($brqOrderId, $context);
        if (!$this->checkoutHelper->checkDuplicatePush($order, $orderTransactionId, $context)) {
            return $this->json(['status' => false, 'message' => $this->trans('buckaroo.messages.pushAlreadySend')]);
        }

        if ($brqTransactionType != ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_GROUP_TRANSACTION) {
            $this->logger->info(__METHOD__ . "|10|");
            $this->checkoutHelper->saveBuckarooTransaction($request, $context);
        }

        $transaction = $this->checkoutHelper->getOrderTransaction($orderTransactionId, $context);

        $totalPrice = $order->getPrice()->getTotalPrice();

        //Check if the push is a refund request or cancel authorize
        if (isset($brqAmountCredit)) {
            $this->logger->info(__METHOD__ . "|15|", [$brqAmountCredit]);
            if ($status != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS && $brqTransactionType == ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_CANCEL) {
                $this->logger->info(__METHOD__ . "|20|");
                return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.paymentCancelled')]);
            }

            $alreadyRefunded = 0;
            if ($orderTransaction = $this->checkoutHelper->getOrderTransactionById(
                $context,
                $orderTransactionId
            )) {
                $this->logger->info(__METHOD__ . "|21|");
                $customFields = $orderTransaction->getCustomFields() ?? [];
                if (!empty($customFields['alreadyRefunded'])) {
                    $this->logger->info(__METHOD__ . "|22|");
                    $alreadyRefunded = $customFields['alreadyRefunded'];
                }
            }

            $this->logger->info(
                __METHOD__ . "|23|",
                [$brqAmountCredit, $alreadyRefunded, $brqAmountCredit + $alreadyRefunded, $totalPrice]
            );
            $status = $this->checkoutHelper->areEqualAmounts($brqAmountCredit + $alreadyRefunded, $totalPrice)
                ? 'refunded'
                : 'partial_refunded';
            $this->logger->info(__METHOD__ . "|25|", [$status]);
            $this->checkoutHelper->saveTransactionData(
                $orderTransactionId,
                $context,
                [$status => 1, 'alreadyRefunded' => $brqAmountCredit + $alreadyRefunded]
            );

            $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);

            return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.refundSuccessful')]);
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            $this->logger->info(__METHOD__ . "|30|");
            try {
                if ($this->checkoutHelper->isOrderState(['cancel'], $brqOrderId, $context)) {
                    $this->logger->info(__METHOD__ . "|35|");
                    $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, 'reopen');
                }

                if ($this->checkoutHelper->isTransitionPaymentState(['refunded', 'partial_refunded'], $orderTransactionId, $context)) {
                    $this->logger->info(__METHOD__ . "|40|");
                    return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.paymentUpdatedEarlier')]);
                }

                $customFields = $this->checkoutHelper->getCustomFields($order, $context);
                $paymentSuccesStatus = $this->checkoutHelper->getSettingsValue('paymentSuccesStatus', $salesChannelContext->getSalesChannelId()) ? $this->checkoutHelper->getSettingsValue('paymentSuccesStatus', $salesChannelContext->getSalesChannelId()) : "completed";
                $alreadyPaid = round($brqAmount + ($customFields['alreadyPaid'] ?? 0), 2);
                $paymentState        = ($alreadyPaid >= round($totalPrice, 2)) ? $paymentSuccesStatus : "pay_partially";
                $data                = [];
                if ($paymentMethod && (strtolower($paymentMethod) == 'klarnakp')) {
                    $this->logger->info(__METHOD__ . "|42|");
                    $paymentState              = 'do_pay';
                    $data['reservationNumber'] = $request->request->get('brq_SERVICE_klarnakp_ReservationNumber');
                }
                $this->logger->info(__METHOD__ . "|45|", [$paymentState, $brqAmount, $totalPrice]);
                $this->checkoutHelper->transitionPaymentState($paymentState, $orderTransactionId, $context);
                $data = array_merge($data, [
                    'originalTransactionKey' => $request->request->get('brq_transactions'),
                    'brqPaymentMethod'       => $paymentMethod ? $paymentMethod : $request->request->get('brq_transaction_method'),
                    'alreadyPaid' => $alreadyPaid,
                ]);
                $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, $data);

                if ($orderStatus = $this->checkoutHelper->getSettingsValue('orderStatus', $salesChannelContext->getSalesChannelId())) {
                    if ($orderStatus == 'complete') {
                        $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, 'process');
                    }
                    $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, $orderStatus);
                }

                $this->logger->info(__METHOD__ . "|50.1|");
                if (!$this->checkoutHelper->isInvoiced($brqOrderId, $context)
                    && !$this->checkoutHelper->isCreateInvoiceAfterShipment($brqTransactionType, false, $salesChannelContext->getSalesChannelId())) {
                    $this->logger->info(__METHOD__ . "|50.2|");
                    if (round($brqAmount, 2) == round($totalPrice, 2)) {
                        $this->checkoutHelper->generateInvoice($brqOrderId, $context, $brqInvoicenumber, $salesChannelContext->getSalesChannelId());
                    }
                }
            } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
                 | StateMachineStateNotFoundException $exception) {
                $this->logger->info(__METHOD__ . "|55|");
                throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
            }
            $this->logger->info(__METHOD__ . "|60|");
            return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.paymentUpdated')]);
        }

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR, ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER, ResponseStatus::BUCKAROO_STATUSCODE_FAILED, ResponseStatus::BUCKAROO_STATUSCODE_REJECTED])) {

            if ($this->checkoutHelper->isTransitionPaymentState(['paid','pay_partially'], $orderTransactionId, $context)) {
                return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.skippedPush')]);
            }

            $paymentFailedStatus = $this->checkoutHelper->getSettingsValue('paymentFailedStatus',  $salesChannelContext->getSalesChannelId()) ? $this->checkoutHelper->getSettingsValue('paymentFailedStatus',  $salesChannelContext->getSalesChannelId()) : "cancelled";

            $this->checkoutHelper->transitionPaymentState($paymentFailedStatus, $orderTransactionId, $context);
            $this->checkoutHelper->changeOrderStatus($brqOrderId, $context, 'cancel');

            return $this->json(['status' => true, 'message' => $this->trans('buckaroo.messages.orderCancelled')]);
        }

        return $this->json(['status' => false, 'message' => $this->trans('buckaroo.messages.paymentError')]);
    }

    /**
     * @Route("/buckaroo/finalize", name="buckaroo.payment.finalize", defaults={"csrf_protected"=false}, methods={"POST","GET"})
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function finalizeBuckaroo(Request $request, SalesChannelContext $salesChannelContext)
    {
        if ($request->query->getBoolean('back_return')) {
            $status = 890;
            $statusMessage = 'The transaction was cancelled by the user.';
            $orderId = $this->get('session')->get('buckaroo_order_number');
            if ($token = $request->query->get('ADD_sw-context-token')) {
                $this->get('session')->set('sw-context-token', $token);
            }
        } else {
            $status = $request->request->get('brq_statuscode');
            $statusMessage = $request->request->get('brq_statusmessage');
            $orderId       = $request->request->get('ADD_orderId');
        }

        if (
            !empty($request->request->get('brq_payment_method'))
            && ($request->request->get('brq_payment_method') == 'paypal')
            && ($status == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        ) {
            $status = ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
            $request->query->set('cancel', 1);
        }

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING])) {
            return $this->renderStorefront('@Storefront/storefront/buckaroo/page/finalize/_page2.html.twig', [
                'messages' => [['type' => 'success', 'text' => $this->trans('buckaroo.messages.return791')]],
            ]);
        }

        if (in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS, ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS])) {
            if ($token = $request->request->get('ADD_sw-context-token')) {
                $this->get('session')->set('sw-context-token', $token);
            }

            return new RedirectResponse($this->generateUrl('frontend.checkout.finish.page', ['orderId' => $request->request->get('ADD_orderId')]));
        }

        if ($request->query->getBoolean('cancel')) {
            $messages[] = ['type' => 'warning', 'text' => $this->trans('buckaroo.messages.userCanceled')];
        }

        if ($error = $request->query->filter('error')) {
            $messages[] = ['type' => 'danger', 'text' => base64_decode($error)];
        }

        if ($statusMessage == 'Failed' && in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_FAILED])) {
            $messages[] = ['type' => 'danger', 'text' => $this->trans('buckaroo.messages.incorrectPin')];
        }

        if (empty($messages)) {
            $statusMessage = $this->checkoutHelper->getStatusMessageByStatusCode($status);
            $messages[] = ['type' => 'danger', 'text' => $statusMessage ? $statusMessage : $this->trans('errorOccurred')];
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
