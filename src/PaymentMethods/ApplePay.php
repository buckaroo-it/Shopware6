<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\ApplePayPaymentHandler;

class ApplePay extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'applepay';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Apple Pay';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Apple Pay';
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/applepay.svg';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return ApplePayPaymentHandler::class;
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
                'description' => 'Bezahlen mit Apple Pay',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
