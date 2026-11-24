<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\BizumPaymentHandler;

class Bizum extends AbstractPayment
{
    public function getBuckarooKey(): string
    {
        return 'bizum';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'Bizum';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with Bizum';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return BizumPaymentHandler::class;
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
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/bizum.svg';
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
                'description' => 'Bezahlen mit Bizum',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name' => $this->getName(),
                'description' => 'Betalen met Bizum',
            ],
            'fr-FR' => [
                'name' => $this->getName(),
                'description' => 'Payer avec Bizum',
            ],
        ];
    }
}
