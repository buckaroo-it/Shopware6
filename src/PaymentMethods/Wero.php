<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\WeroPaymentHandler;

class Wero extends AbstractPayment
{
    /*
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return 'wero';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Wero';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Wero';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return WeroPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/wero.svg';
    }

    /**
     * {@inheritDoc}
     *
     * @return array<mixed>
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name' => $this->getName(),
                'description' => 'Bezahlen mit Wero',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name' => 'Betalen met Wero',
                'description' => $this->getDescription(),
            ],
            'fr-FR' => [
                'name' => $this->getName(),
                'description' => 'Payer avec Wero',
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'redirect';
    }

    /**
     * {@inheritDoc}
     */
    public function canRefund(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function canCapture(): bool
    {
        return true;
    }
}
