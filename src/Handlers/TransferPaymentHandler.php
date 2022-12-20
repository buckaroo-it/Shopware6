<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Transfer;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TransferPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = Transfer::class;

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
        $order = $transaction->getOrder();
        $address = $order->getBillingAddress();
        $customer = $order->getOrderCustomer();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        return [
            'email' => $customer->getEmail(),
            'country' => $address->getCountry()->getIso(),
            'customer'      => [
                'firstName' => $address->getFirstName(),
                'lastName' => $address->getLastName()
            ],
            'dateDue' => $this->getDateDue($salesChannelId),
            'sendMail' => $this->canSendEmail($salesChannelId),
        ];
    }

    protected function getDateDue(string $salesChannelId): string
    {
        $now = new \DateTime();
        $days = $this->getSetting('transferDateDue', $salesChannelId);

        if (is_scalar($days) && (int)$days <= 0) {
            $days = 7;
        }
        $now->modify('+' . $days . ' day');
        return $now->format('Y-m-d');
    }

    protected function canSendEmail(string $salesChannelId): bool
    {
        return $this->getSetting('transferSendEmail', $salesChannelId) == 1;
    }
}
