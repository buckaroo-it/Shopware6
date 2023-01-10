<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Giftcards;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class GiftcardsPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Giftcards::class;

    /**
     * Get parameters for specific payment method
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array
     */
    protected function getMethodPayload(
        AsyncPaymentTransactionStruct $transaction,
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
    ): string
    {
        return 'payRedirect';
    }

    protected function getAllowedGiftcards(string $salesChannelId)
    {
        $allowedgiftcards = $this->asyncPaymentService
            ->settingsService
            ->getSetting('allowedgiftcards', $salesChannelId);

        if (
            is_array($allowedgiftcards) &&
            count($allowedgiftcards)
        ) {
            return implode(",", $allowedgiftcards);
        }
        return 'ideal';
    }
}
