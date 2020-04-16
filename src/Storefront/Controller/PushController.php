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

use Buckaroo\Shopware6\Helpers\BkrHelper;

use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Payment\Exception\InvalidOrderException;

/**
 */
class PushController extends StorefrontController
{
    const BUCK_PUSH_CANCEL_AUTHORIZE_TYPE  = 'I014';
    const BUCK_PUSH_ACCEPT_AUTHORIZE_TYPE  = 'I013';
    const BUCK_PUSH_STATUS_SUCCESS  = '190';

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        BkrHelper $bkrHelper,
        EntityRepositoryInterface $transactionRepository,
        CheckoutHelper $checkoutHelper
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->checkoutHelper = $checkoutHelper;
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

        //Check if the push is a refund request or cancel authorize
        if (isset($brq_amount_credit)) {
            if($status != self::BUCK_PUSH_STATUS_SUCCESS && $brq_transaction_type == self::BUCK_PUSH_CANCEL_AUTHORIZE_TYPE){
                return $this->json(['status' => true, 'message' => "Payment cancelled"]);
            }
            $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, ['refunded' => 1 ]);
            $this->checkoutHelper->transitionPaymentState('refunded', $orderTransactionId, $context);
            return $this->json(['status' => true, 'message' => "Refund successful"]);
        }

        if($status == self::BUCK_PUSH_STATUS_SUCCESS){
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

        return $this->json(['status' => false, 'message' => "Payment error"]);
    }
    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/buckaroo/finalize", name="buckaroo.payment.finalize", defaults={"csrf_protected"=false}, methods={"POST"})
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
        if($status == self::BUCK_PUSH_STATUS_SUCCESS){
            return new RedirectResponse('/checkout/finish?orderId=' . $request->request->get('ADD_orderId'));
        }

        $message = 'Payment failed';
        if ($request->query->getBoolean('cancel')) {
            $message = 'Customer canceled the payment on the Buckaroo page';
        }

        throw new CustomerCanceledAsyncPaymentException(
            $transactionId,
            $message
        );

    }
}
