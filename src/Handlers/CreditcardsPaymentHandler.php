<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Creditcards;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class CreditcardsPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Creditcards::class;

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
                'name'              => $dataBag->get('creditcards_issuer'),
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
        return 'PayWithToken';
    }

    private function isEncripted(RequestDataBag $dataBag): bool
    {
        return $dataBag->has('creditcards_issuer') && $dataBag->has('encryptedCardData');
    }
}
