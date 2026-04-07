<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\GooglePayPaymentHandler;

class GooglePay extends AbstractPayment
{
    public function getBuckarooKey(): string
    {
        return 'googlepay';
    }

    public function getName(): string
    {
        return 'Google Pay';
    }

    public function getDescription(): string
    {
        return 'Pay with Google Pay';
    }

    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/googlepay.svg';
    }

    public function getPaymentHandler(): string
    {
        return GooglePayPaymentHandler::class;
    }

    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * @return array<mixed>
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit Google Pay',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
