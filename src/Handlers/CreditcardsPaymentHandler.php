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
        if ($this->isHostedFields($dataBag)) {
            return [
                    'name'              => $dataBag->get('selected-issuer'),
                    'sessionId'         => $dataBag->get('buckaroo-token')
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

    /**
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param RequestDataBag $dataBag
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    public function buildPayParameters(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $dataBag,
        string $paymentCode
    ): array {

        return array_merge_recursive(
            [
                'customerIPAddress' => (Request::createFromGlobals())->getClientIp()
            ],
            $this->getBillingData($order, $dataBag),
            $this->getShippingData($order, $dataBag),
            $this->getArticles($order, $paymentCode)
        );
    }
    private function isHostedFields(RequestDataBag $dataBag): bool
    {
        return $dataBag->has('buckaroo-token');
    }
}
