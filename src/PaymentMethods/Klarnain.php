<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\KlarnainPaymentHandler;

class Klarnain extends AbstractPayment
{
    /*
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return 'klarnain';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Klarna Slice it';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Klarna Slice it';
    }

    public function getVersion(): string
    {
        return '0';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return KlarnainPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/klarna.svg';
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
                'description' => 'Bezahlen mit Klarna Slice it',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }

    public function canCapture(): bool
    {
        return false;
    }
}
