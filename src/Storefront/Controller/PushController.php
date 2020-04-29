<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;

use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;

/**
 */
class PushController extends StorefrontController
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        EntityRepositoryInterface $transactionRepository,
        CheckoutHelper $checkoutHelper,
        EntityRepositoryInterface $orderRepository
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->checkoutHelper = $checkoutHelper;
        $this->orderRepository = $orderRepository;
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
        $orderTransactionId = $request->request->get('ADD_orderTransactionId');
        $context = $salesChannelContext->getContext();
        $brq_amount_credit = $request->request->get('brq_amount_credit');
        $status = $request->request->get('brq_statuscode');
        $brq_transaction_type = $request->request->get('brq_transaction_type');

        $validSignature = $this->checkoutHelper->validateSignature();
        if(!$validSignature){
            return $this->json(['status' => false, 'message' => 'Signature from push is incorrect']);
        }
        
        $transaction = $this->checkoutHelper->getOrderTransaction($orderTransactionId, $context);

        //Check if the push is a refund request or cancel authorize
        if (isset($brq_amount_credit)) {
            if($status != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS && $brq_transaction_type == ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_CANCEL){
                return $this->json(['status' => true, 'message' => "Payment cancelled"]);
            }

            $totalPrice = $transaction->getAmount()->getTotalPrice();
            $status = ($brq_amount_credit < $totalPrice) ? 'partial_refunded' : 'refunded';
            $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, [$status => 1 ]);

            $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);

            return $this->json(['status' => true, 'message' => "Refund successful"]);
        }

        if($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS){
            try {
                $this->checkoutHelper->transitionPaymentState('completed', $orderTransactionId, $context);
                $data = [
                    'originalTransactionKey' => $request->request->get('brq_transactions'),
                    'brqPaymentMethod' => $request->request->get('brq_transaction_method')
                ];
                $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, $data);
            } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
            | StateMachineStateNotFoundException $exception) {
                throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
            }
            return $this->json(['status' => true, 'message' => "Payment state was updated"]);
        }

        if(in_array($status,[ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR, ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT, ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER, ResponseStatus::BUCKAROO_STATUSCODE_FAILED, ResponseStatus::BUCKAROO_STATUSCODE_REJECTED])){
            $this->checkoutHelper->transitionPaymentState('cancelled', $orderTransactionId, $context);

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
        $transactionId = $request->request->get('ADD_orderTransactionId');
        $status = $request->request->get('brq_statuscode');
        $status_message = $request->request->get('brq_statusmessage');
        $orderId = $request->request->get('ADD_orderId');

        if(in_array($status, [ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS,ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS,ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING])){
            return new RedirectResponse('/checkout/finish?orderId=' . $request->request->get('ADD_orderId'));
        }

        if ($request->query->getBoolean('cancel')) {
            $messages[] = ['type' => 'warning', 'text' => [$this->trans('According to our system, you have canceled the payment. If this is not the case, please contact us.')]];
        }

        if ($error = $request->query->filter('error')) {
            $messages[] = ['type' => 'danger', 'text' => [base64_decode($error)]];
        }

        if(empty($messages)){
            $messages[] = ['type' => 'danger', 'text' => [$status_message ? $status_message : $this->trans('Unfortunately an error occurred while processing your payment. Please try again. If this error persists, please choose a different payment method.')]];
        }

        if (!$orderId && $orderId = $request->query->filter('orderId')) {}

        if($orderId){
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $orderId))
                ->addAssociation('lineItems')
                ->addAssociation('lineItems.cover');
            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();
            $lineItems = $order->getNestedLineItems();

            foreach ($messages as $mkey => $message) {
                foreach ($message['text'] as $tkey => $text) {
                    $this->addFlash($message['type'], $text);
                }
            }

/*            foreach ($lineItems as $item) {
                $lineItemsNew[] = [
                    $item->get('referencedId') => [
                        'id' => $item->get('identifier'),
                        'quantity' => $item->get('quantity'),
                        'type' => $item->get('type')
                    ]
               ];
            }
            $this->checkoutHelper->addLineItems($lineItemsNew, $salesChannelContext);*/
        }

        return $this->renderStorefront('@Storefront/storefront/buckaroo/page/finalize/_page.html.twig', [
            'messages' => $messages,
            'orderDetails' => $lineItems
        ]);

    }

}
