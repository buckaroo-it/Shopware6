<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\PayByBank;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\CustomerService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PayByBankPaymentHandler extends PaymentHandlerSimple
{
    public const ISSUER_LABEL = 'buckaroo_last_used_paybybank_issuer';

    public string $paymentClass = PayByBank::class;

    protected CustomerService $customerService;

    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        CustomerService $customerService
    ) {
        parent::__construct($asyncPaymentService);
        $this->customerService = $customerService;
    }

    /**
     * Get parameters for specific payment method
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [
            'issuer' => $dataBag->get('payBybankMethodId'),
        ];
    }

    /**
     * Hook before modern pay; updates issuer in customer profile.
     */
    protected function beforePayModern(
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        Context $context
    ): void {
        $transactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->asyncPaymentService->getTransaction($transactionId, $context);
        if ($orderTransaction === null) {
            return;
        }
        $order = $orderTransaction->getOrder();
        if ($order instanceof OrderEntity) {
            $contextToken = $this->getContextTokenFromDataBag($dataBag);
            $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
                $context,
                $order->getSalesChannelId(),
                $contextToken
            );
            $this->updateCustomerIssuer($dataBag, $salesChannelContext);
        }
    }

    protected function updateCustomerIssuer(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $customer = $salesChannelContext->getCustomer();
        $lastPayByBank = $dataBag->get('payBybankMethodId');

        if (
            $customer !== null
            && $lastPayByBank !== null
            && $lastPayByBank !== $customer->getCustomFieldsValue(self::ISSUER_LABEL)
        ) {
            $this->customerService->updateCustomerData(
                $customer,
                $salesChannelContext->getContext(),
                [self::ISSUER_LABEL => $lastPayByBank]
            );
        }
    }
}
