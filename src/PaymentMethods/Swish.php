<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\SwishPaymentHandler;

class Swish extends AbstractPayment
{
    public function getBuckarooKey(): string
    {
        return 'swish';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Swish';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Swish';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return SwishPaymentHandler::class;
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
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/swish.svg';
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
                'description' => 'Bezahlen mit Swish',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name' => $this->getName(),
                'description' => 'Betalen met Swish',
            ],
            'fr-FR' => [
                'name' => $this->getName(),
                'description' => 'Payer avec Swish',
            ],
        ];
    }
}
