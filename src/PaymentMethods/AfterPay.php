<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;

class AfterPay extends AbstractPayment
{
    /*
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return 'afterpay';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Riverty';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Buy now, pay in 14 days';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return AfterPayPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/afterpay.svg';
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
                'description' => 'Bezahlen innerhalb von 14 Tagen',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name' => $this->getName(),
                'description' => 'Achteraf betalen binnen 14 dagen',
            ],
            'fr-FR' => [
                'name' => $this->getName(),
                'description' => 'Payer sous 14 jours',
            ],
        ];
    }

    public function canCapture(): bool
    {
        return true;
    }
}
