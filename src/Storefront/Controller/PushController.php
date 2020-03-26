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
use Buckaroo\Shopware6\Helper\CheckoutHelper;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Buckaroo\Shopware6\Helper\BkrHelper;

/**
 */
class PushController extends StorefrontController
{
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
        try {
            $this->checkoutHelper->transitionPaymentState('completed', $orderTransactionId, $context);
            $data = [
                'originalTransactionKey' => $request->request->get('brq_transactions')
            ];
            $this->checkoutHelper->saveTransactionData($orderTransactionId, $context, $data);
        } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
        | StateMachineStateNotFoundException $exception) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
        }

        return new RedirectResponse('/checkout/finish?orderId=' . $request->request->get('ADD_orderId'));
    }
}
