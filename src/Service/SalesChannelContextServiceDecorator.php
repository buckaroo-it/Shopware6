<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

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
                    $parameters->getContext()
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
        $token = $request->attributes->get('sw-context-token')
            ?? $request->query->get('add_sw-context-token')
            ?? $request->request->get('add_sw-context-token')
            ?? $request->query->get('sw-context-token')
            ?? $request->request->get('sw-context-token')
            ?? $request->cookies->get('sw-context-token');
        return is_string($token) && $token !== '' ? $token : null;
    }
}
