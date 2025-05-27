<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\IdealPaymentHandler;

class Ideal extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'ideal';
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return '2';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'iDEAL';
    }
    public function getTechnicalName(): string
    {
        return 'buckaroo_ideal';
    }
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with iDEAL';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return IdealPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/buckaroo/payments/ideal.svg';
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
                'description' => 'Bezahlen mit iDEAL',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
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
        return 'direct';
    }
}
