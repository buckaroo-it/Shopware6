<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles the back-button / double-redirect scenario for async payments (e.g. Wero, iDEAL).
 *
 * Shopware's _sw_payment_token is one-time-use: after the first successful finalize
 * the record is deleted (or marked consumed) from the payment_token table. If the
 * customer presses back and the Buckaroo page re-fires the redirect with the same URL,
 * Shopware throws CHECKOUT__PAYMENT_TOKEN_INVALIDATED before the plugin handler is
 * reached, resulting in a raw error page.
 *
 * This subscriber intercepts that exception. Because the JWT itself is still
 * cryptographically valid (only the DB record is gone), we decode the payload directly —
 * bypassing JWTFactoryV2::parseToken() which would throw again — to recover the
 * finishUrl ('ful' claim) and errorUrl ('eul' claim) that were embedded at token
 * creation time. We then check the transaction state and redirect silently.
 *
 * JWT claims written by JWTFactoryV2::generateToken():
 *   sub → transactionId   (via ->relatedTo())
 *   ful → finishUrl       (→ checkout/finish?orderId=...)
 *   eul → errorUrl        (→ account/order/edit/...)
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
            $redirectUrl = $this->resolveRedirectUrl($paymentToken, $request);
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
        // expose getErrorCode() returning the same constant.
        if (method_exists($exception, 'getErrorCode')) {
            return $exception->getErrorCode() === self::TOKEN_INVALIDATED_CODE;
        }

        return str_contains(get_class($exception), 'TokenInvalidated');
    }

    /**
     * Decode the JWT payload directly — no parseToken() call, no DB access.
     * JWTFactoryV2::parseToken() would throw the same tokenInvalidated exception
     * because the record no longer exists in payment_token. The JWT signature is
     * still valid; we only need the plaintext claims embedded in the token.
     */
    private function resolveRedirectUrl(string $paymentToken, Request $request): string
    {
        $payload = $this->decodeJwtPayload($paymentToken);

        // 'ful' and 'eul' are stored as relative paths (e.g. /checkout/finish?orderId=...)
        $finishUrl     = $this->makeAbsolute($payload['ful'] ?? null, $request);
        $errorUrl      = $this->makeAbsolute($payload['eul'] ?? null, $request);
        $transactionId = isset($payload['sub']) && is_string($payload['sub']) ? $payload['sub'] : null;

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

        // When the state cannot be determined: favour the confirmation page.
        // This subscriber only fires after the token was already consumed once,
        // meaning a prior finalize did run (successfully or not).
        return $finishUrl ?? $errorUrl ?? $this->accountOrdersUrl();
    }

    /**
     * Decode the payload (second) segment of a JWT without signature verification.
     * We only need the plaintext claims — the signature was already verified by
     * Shopware core before tokenInvalidated was thrown.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if the token is structurally malformed
     */
    private function decodeJwtPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT: expected 3 dot-separated parts');
        }

        // JWT uses base64url encoding (RFC 4648 §5) — swap - and _ back to + and /
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('JWT payload could not be base64-decoded');
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JWT payload is not a valid JSON object');
        }

        return $data;
    }

    private function makeAbsolute(?string $path, Request $request): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $request->getSchemeAndHttpHost() . $path;
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
