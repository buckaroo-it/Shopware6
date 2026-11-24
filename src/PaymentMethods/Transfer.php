<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\TransferPaymentHandler;

class Transfer extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'transfer';
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
        return 'Bank Transfer';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Bank Transfer';
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/sepa-credittransfer.svg';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return TransferPaymentHandler::class;
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
                'description' => 'Bezahlen mit Bank Transfer',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
