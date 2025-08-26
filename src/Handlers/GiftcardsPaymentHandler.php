<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Giftcards;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class GiftcardsPaymentHandler extends PaymentHandler
{
    protected string $paymentClass = Giftcards::class;

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
        return [
            'continueOnIncomplete' => 'RedirectToHTML',
            'servicesSelectableByClient' => $this->getAllowedGiftcards(
                $salesChannelContext->getSalesChannelId()
            )
        ];
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
        return 'payRedirect';
    }

    protected function getAllowedGiftcards(string $salesChannelId): string
    {
        $allowedGiftcards = $this->asyncPaymentService
            ->settingsService
            ->getSetting('allowedgiftcards', $salesChannelId);

        $allowedServices = [];

        if (
            is_array($allowedGiftcards) &&
            count($allowedGiftcards)
        ) {
            $allowedServices = $allowedGiftcards;
        }

        $allowedMethods = $this->getAllowedMethods($salesChannelId);

        if (count($allowedMethods) === 0) {
            $allowedMethods = ['ideal']; //defaults to ideal payment method
        }
        $allowedServices = array_merge($allowedServices, $allowedMethods);

        return implode(",", $allowedServices);
    }

    protected function getAllowedMethods(string $salesChannelId): array
    {
        $allowedMethods = $this->asyncPaymentService
            ->settingsService
            ->getSetting('giftcardsPaymentmethods', $salesChannelId);

        if (
            !is_array($allowedMethods) ||
            count($allowedMethods) == 0
        ) {
            return [];
        }

        return $allowedMethods;
    }
}
