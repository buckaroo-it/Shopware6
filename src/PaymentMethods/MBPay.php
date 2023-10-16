<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\MBPayPaymentHandler;

class MBPay extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'mbpay';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Buckaroo MBWay';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with MBWay';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/buckaroo/payments/mbpay.svg';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return MBPayPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
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
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit MBWay',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
