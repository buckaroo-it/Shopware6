<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\PayconiqPaymentHandler;

class Payconiq extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'payconiq';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Payconiq';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Payconiq';
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/payconiq.svg';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return PayconiqPaymentHandler::class;
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
                'description' => 'Bezahlen mit Payconiq',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
