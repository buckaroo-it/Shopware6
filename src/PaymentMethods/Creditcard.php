<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\CreditcardPaymentHandler;

class Creditcard extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'creditcard';
    }

    public function getVersion(): string
    {
        return '2';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Credit and debit card';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Credit or debit card';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return CreditcardPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/creditcards.svg';
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
                'description' => 'Bezahlen mit Buckaroo-Kartenzahlung',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'direct';
    }
}
