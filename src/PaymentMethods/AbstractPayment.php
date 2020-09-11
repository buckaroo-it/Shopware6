<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\PaymentMethods;

abstract class AbstractPayment implements PaymentMethodInterface
{
    /**
     * @return string
     */
    public function getVersion(): string
    {
        return '1';
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate():  ? string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getType(): string
    {
        return 'redirect';
    }

    public function canRefund(): bool
    {
        return true;
    }

    public function canCapture(): bool
    {
        return false;
    }

}
