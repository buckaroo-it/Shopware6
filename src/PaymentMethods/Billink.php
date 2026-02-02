<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\BillinkPaymentHandler;

class Billink extends AbstractPayment
{
    public function getBuckarooKey(): string
    {
        return 'Billink';
    }

    public function getName(): string
    {
        return 'Billink - achteraf betalen';
    }

    public function getDescription(): string
    {
        return 'Pay with Billink - achteraf betalen';
    }

    public function getPaymentHandler(): string
    {
        return BillinkPaymentHandler::class;
    }

    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/billink.svg';
    }

    /**
     * @return array<mixed>
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit Billink - achteraf betalen',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }

    public function canCapture(): bool
    {
        return false;
    }
}
