<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\In3PaymentHandler;

class In3 extends AbstractPayment
{

    public const DEFAULT_NAME = 'In3';
    public const V2_NAME = 'In3';
    /*
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return 'capayable';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return  self::DEFAULT_NAME;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay in 3 installments, 0% interest';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return In3PaymentHandler::class;
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
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/in3.svg';
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
                'description' => 'In 3 Raten bezahlen, 0 % Zinsen',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
            'nl-NL' => [
                'name'        => $this->getName(),
                'description' => 'In 3 delen betalen, 0% rente',
            ],
            'fr-FR' => [
                'name'        => $this->getName(),
                'description' => 'Payer en 3 fois, 0 % d\'intérêt',
            ],
        ];
    }
}
