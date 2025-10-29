<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\TwintPaymentHandler;

class Twint extends AbstractPayment
{
    /**
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return 'twint';
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return '0';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'TWINT';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with TWINT';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return TwintPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/twint.svg';
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
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit TWINT',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name'        => $this->getName(),
                'description' => 'Betalen met TWINT',
            ],
            'fr-FR' => [
                'name'        => $this->getName(),
                'description' => 'Payer avec TWINT',
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
}


