<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\ShopwareException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Checkout\Payment\PaymentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Shopware\Core\Checkout\Payment\Exception\InvalidTokenException;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Routing\Exception\InvalidRequestParameterException;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;

class PaymentController extends AbstractController
{
    private PaymentService $paymentService;

    private OrderConverter $orderConverter;

    private TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2;

    private EntityRepository $orderRepository;

    /**
     *  Required for payments on shopware older than v6.4.2.0
     */
    public function __construct(
        PaymentService $paymentService,
        OrderConverter $orderConverter,
        TokenFactoryInterfaceV2 $tokenFactoryInterfaceV2,
        EntityRepository $orderRepository
    ) {
        $this->paymentService = $paymentService;
        $this->orderConverter = $orderConverter;
        $this->tokenFactoryInterfaceV2 = $tokenFactoryInterfaceV2;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * phpcs:ignore Generic.Files.LineLength.TooLong
     * @Route("/buckaroo/payment/finalize-transaction", defaults={"auth_required"=false, "csrf_protected"=false}, options={"seo"="false"}, name="buckaroo.payment.finalize.transaction", methods={"GET", "POST"})
     */
    public function finalizeTransaction(Request $request): Response
    {
        $paymentToken = $request->get('_sw_payment_token');

        if ($paymentToken === null) {
            throw new MissingRequestParameterException('_sw_payment_token');
        }

        if (!is_string($paymentToken)) {
            throw new InvalidRequestParameterException('_sw_payment_token');
        }

        $salesChannelContext = $this->assembleSalesChannelContext($paymentToken);

        $result = $this->paymentService->finalizeTransaction(
            $paymentToken,
            $request,
            $salesChannelContext
        );

        $response = $this->handleException($result);

        if ($response !== null) {
            return $response;
        }

        $finishUrl = $result->getFinishUrl();
        if ($finishUrl) {
            return new RedirectResponse($finishUrl);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function handleException(TokenStruct $token): ?Response
    {
        if ($token->getException() === null) {
            return null;
        }

        if ($token->getErrorUrl() === null) {
            return null;
        }

        $url = $token->getErrorUrl();

        $exception = $token->getException();
        if ($exception instanceof ShopwareException) {
            return new RedirectResponse(
                $url . (parse_url($url, \PHP_URL_QUERY) ? '&' : '?') . 'error-code=' . $exception->getErrorCode()
            );
        }

        return new RedirectResponse($url);
    }

    private function assembleSalesChannelContext(string $paymentToken): SalesChannelContext
    {
        $context = Context::createDefaultContext();

        $transactionId = $this->tokenFactoryInterfaceV2->parseToken($paymentToken)->getTransactionId();
        if ($transactionId === null) {
            throw new InvalidTokenException($paymentToken);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('transactions.id', $transactionId));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('orderCustomer');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new InvalidTokenException($paymentToken);
        }

        return $this->orderConverter->assembleSalesChannelContext($order, $context);
    }
}
