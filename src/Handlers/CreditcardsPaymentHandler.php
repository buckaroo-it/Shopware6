<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Creditcards;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class CreditcardsPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Creditcards::class;

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
        if($this->isEncripted($dataBag)) {
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
        if ($this->isEncripted($dataBag)) {
            return 'payEncrypted';
        }
        return parent::getMethodAction($dataBag, $salesChannelContext, $paymentCode);
    }

    private function isEncripted(RequestDataBag $dataBag): bool
    {
        return $dataBag->has('creditcards_issuer') && $dataBag->has('encryptedCardData');
    }
}
