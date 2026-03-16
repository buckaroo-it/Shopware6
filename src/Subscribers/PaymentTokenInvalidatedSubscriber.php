<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles the back-button scenario for async payments (e.g. Wero, iDEAL).
 *
 * After a successful payment, Shopware deletes the one-time _sw_payment_token.
 * If the customer presses back and the Buckaroo payment page re-fires its auto-redirect,
 * Shopware throws CHECKOUT__PAYMENT_TOKEN_INVALIDATED (HTTP 410) before the plugin is
 * even reached. Without this subscriber the customer sees a raw error page.
 *
 * This subscriber intercepts that exception, decodes the (still JWT-valid) token to
 * recover the finishUrl / errorUrl stored at token creation time, checks the transaction
 * state, and issues a silent redirect to the appropriate page.
 */
class PaymentTokenInvalidatedSubscriber implements EventSubscriberInterface
{
    private const TOKEN_INVALIDATED_CODE = 'CHECKOUT__PAYMENT_TOKEN_INVALIDATED';

    /** Transaction technical names that represent a completed successful payment */
    private const SUCCESS_STATES = [
        'paid',
        'paid_partially',
        'authorized',
    ];

    public function __construct(
        private readonly TokenFactoryInterfaceV2 $tokenFactory,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->isTokenInvalidatedException($event->getThrowable())) {
            return;
        }

        $request = $event->getRequest();
        $paymentToken = $request->query->get('_sw_payment_token');

        if (!is_string($paymentToken) || $paymentToken === '') {
            return;
        }

        try {
            $redirectUrl = $this->resolveRedirectUrl($paymentToken);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Buckaroo: Could not resolve redirect URL for invalidated payment token — leaving default error handling in place',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $this->logger->info(
            'Buckaroo: Redirecting after invalidated payment token (back-button / double-redirect scenario)',
            ['redirectUrl' => $redirectUrl]
        );

        $event->setResponse(new RedirectResponse($redirectUrl));
        $event->stopPropagation();
    }

    private function isTokenInvalidatedException(\Throwable $exception): bool
    {
        // Shopware 6.4 (TokenInvalidatedException) and 6.5+ (PaymentException) both
        // expose getErrorCode() returning the same constant for this condition.
        if (method_exists($exception, 'getErrorCode')) {
            return $exception->getErrorCode() === self::TOKEN_INVALIDATED_CODE;
        }

        // Safety net: match by class name for any future structural changes.
        return str_contains(get_class($exception), 'TokenInvalidated');
    }

    /**
     * Decode the JWT token (signature still valid, only removed from DB) to recover
     * the finishUrl and errorUrl, then pick the correct destination based on the
     * current transaction state.
     */
    private function resolveRedirectUrl(string $paymentToken): string
    {
        $tokenStruct = $this->tokenFactory->parseToken($paymentToken);

        $finishUrl     = $tokenStruct->getFinishUrl();
        $errorUrl      = $tokenStruct->getErrorUrl();
        $transactionId = $tokenStruct->getTransactionId();

        if ($transactionId !== null) {
            try {
                if ($this->isPaymentSuccessful($transactionId)) {
                    return $finishUrl ?? $this->accountOrdersUrl();
                }

                return $errorUrl ?? $this->accountOrdersUrl();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Buckaroo: Could not determine transaction state for invalidated token — falling back to finishUrl',
                    ['transactionId' => $transactionId, 'exception' => $e->getMessage()]
                );
            }
        }

        // When the transaction state cannot be determined, favour the finish/confirmation
        // page because this subscriber only fires after the token has already been
        // consumed once (i.e. a previous finalize did complete).
        return $finishUrl ?? $errorUrl ?? $this->accountOrdersUrl();
    }

    private function isPaymentSuccessful(string $transactionId): bool
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('stateMachineState');

        $transaction = $this->orderTransactionRepository
            ->search($criteria, Context::createDefaultContext())
            ->first();

        if ($transaction === null) {
            return false;
        }

        $state = $transaction->getStateMachineState();
        if ($state === null) {
            return false;
        }

        return in_array($state->getTechnicalName(), self::SUCCESS_STATES, true);
    }

    private function accountOrdersUrl(): string
    {
        return $this->urlGenerator->generate(
            'frontend.account.order.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
