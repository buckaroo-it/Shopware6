<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

class SalesChannelContextServiceDecorator implements SalesChannelContextServiceInterface
{
    public function __construct(
        private readonly SalesChannelContextServiceInterface $inner,
        private readonly RequestStack $requestStack
    ) {
    }

    public function get(SalesChannelContextServiceParameters $parameters): SalesChannelContext
    {
        $token = $parameters->getToken();

        // When core uses a random payment-context token, replace with request token if available
        if (str_starts_with($token, 'payment-context-')) {
            $requestToken = $this->getContextTokenFromRequest();
            if ($requestToken !== null) {
                $parameters = new SalesChannelContextServiceParameters(
                    $parameters->getSalesChannelId(),
                    $requestToken,
                    $parameters->getLanguageId(),
                    $parameters->getCurrencyId(),
                    $parameters->getDomainId(),
                    $parameters->getOriginalContext(),
                    $parameters->getCustomerId(),
                    $parameters->getImitatingUserId()
                );
            }
        }

        return $this->inner->get($parameters);
    }

    private function getContextTokenFromRequest(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        // Never read the token from query or body parameters: only the token restored by
        // PaymentContextRestoreSubscriber or the request's own context header are trusted.
        $token = $request->attributes->get('sw-context-token')
            ?? $request->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);
        return is_string($token) && $token !== '' ? $token : null;
    }
}
