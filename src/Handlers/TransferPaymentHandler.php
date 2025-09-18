<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\PaymentMethods\Transfer;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class TransferPaymentHandler extends PaymentHandlerSimple
{
    protected string $paymentClass = Transfer::class;

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
        $address = $this->asyncPaymentService->getBillingAddress($order);
        $customer = $this->asyncPaymentService->getCustomer($order);
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        return [
            'email' => $customer->getEmail(),
            'country' => $this->asyncPaymentService->getCountry($address)->getIso(),
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
