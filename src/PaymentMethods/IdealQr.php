<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\IdealQrPaymentHandler;

class IdealQr extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'idealqr';
    }

    public function getVersion(): string
    {
        return '1';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'iDEAL QR';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with iDEAL QR';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return IdealQrPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/ideal-qr.svg';
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
                'description' => 'Bezahlen mit iDEAL QR',
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
