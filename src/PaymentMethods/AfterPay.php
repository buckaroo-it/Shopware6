<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\AfterPayPaymentHandler;

class AfterPay extends AbstractPayment
{

    protected $buckarooKey = 'afterpay';
    /*
     * @return string
     */
    public function getBuckarooKey(): string
    {
        return $this->buckarooKey;
    }

     /*
     * @return string
     */
    public function setBuckarooKey(string $buckarooKey = 'afterpay'): string
    {
        $this->buckarooKey = $buckarooKey;
        return $this->buckarooKey;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Riverty | AfterPay';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with Riverty | AfterPay';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return AfterPayPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia() : string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/logo/afterpay.png';
    }

    /**
     * {@inheritDoc}
     *
     * @return array
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name'        => $this->getName(),
                'description' => 'Bezahlen mit Riverty | AfterPay',
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
