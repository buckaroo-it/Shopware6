<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\PayByBankPaymentHandler;

class PayByBank extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'paybybank';
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
        return 'PayByBank';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with PayByBank';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return PayByBankPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/buckaroo/payments/paybybank.gif';
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
                'description' => 'Bezahlen mit PayByBank',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name'        => 'Betalen per bank',
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
