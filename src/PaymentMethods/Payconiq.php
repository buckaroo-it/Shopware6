<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\PayconiqPaymentHandler;

class Payconiq implements PaymentMethodInterface
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'payconiq';
    }

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
     * @return string
     */
    public function getName(): string
    {
        return 'Payconiq';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with Payconiq';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/buckaroo/logo/payconiq.png';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return PayconiqPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return array
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit Payconiq',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
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
}
