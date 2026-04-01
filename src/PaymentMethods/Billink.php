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
        return 'Billink';
    }

    public function getDescription(): string
    {
        return 'Pay afterwards';
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
                'description' => 'Später bezahlen',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name'        => $this->getName(),
                'description' => 'Achteraf betalen',
            ],
            'fr-FR' => [
                'name'        => $this->getName(),
                'description' => 'Payer plus tard',
            ],
        ];
    }

    public function canCapture(): bool
    {
        return false;
    }
}
