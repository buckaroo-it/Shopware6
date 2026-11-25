<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

use Buckaroo\Shopware6\Handlers\WeChatPayPaymentHandler;

class WeChatPay extends AbstractPayment
{
    /*
    * @return string
    */
    public function getBuckarooKey(): string
    {
        return 'WeChatPay';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'WeChatPay';
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        return 'Pay with WeChatPay';
    }

    /**
     * {@inheritDoc}
     */
    public function getMedia(): string
    {
        return __DIR__ . '/../Resources/views/storefront/buckaroo/payments/wechatpay.svg';
    }

    /**
     * {@inheritDoc}
     */
    public function getPaymentHandler(): string
    {
        return WeChatPayPaymentHandler::class;
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
                'description' => 'Bezahlen mit WeChatPay',
            ],
            'en-GB' => [
                'name' => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
    }
}
