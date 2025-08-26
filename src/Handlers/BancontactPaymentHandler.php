<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Bancontact;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class BancontactPaymentHandler extends PaymentHandler
{
    protected string $paymentClass = Bancontact::class;

    private function isEncripted(RequestDataBag $dataBag): bool
    {
        return $dataBag->has('encryptedCardData');
    }

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($this->isEncripted($dataBag)) {
            return [
                'encryptedCardData' => $dataBag->get('encryptedCardData')
            ];
        }
        return [];
    }
    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        if ($this->isEncripted($dataBag)) {
            return 'payEncrypted';
        }
        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }
}
