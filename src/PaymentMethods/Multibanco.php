<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\MultibancoPaymentHandler;

class Multibanco extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'multibanco';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Multibanco';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Multibanco';
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/multibanco.svg';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return MultibancoPaymentHandler::class;
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
     *
     * @return array<mixed>
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name' => $this->getName(),
                'description' => 'Bezahlen mit Multibanco',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
