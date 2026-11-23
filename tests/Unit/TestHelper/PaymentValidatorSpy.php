<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\TestHelper;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Stands in for Shopware's PaymentProcessor (6.6.x / 6.7), which OrderService
 * reaches through PaymentServiceFactory and calls by method name.
 */
final class PaymentValidatorSpy
{
    /** @var list<array{Cart, SalesChannelContext}> */
    public array $validated = [];

    public ?\Throwable $failWith = null;

    public function validate(Cart $cart, RequestDataBag $dataBag, SalesChannelContext $context): null
    {
        $this->validated[] = [$cart, $context];

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return null;
    }
}
