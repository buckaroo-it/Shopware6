<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\PaymentMethods\PayByBank;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;

class PayByBankPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = PayByBank::class;

    public const ISSUER_LABEL = 'buckaroo_last_used_paybybank_issuer';

    protected CustomerService $customerService;

    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        CustomerService $customerService
    ) {
        parent::__construct($asyncPaymentService);
        $this->customerService = $customerService;
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
            $contextToken = $dataBag->get('sw-context-token', '');
            $salesChannelContext = $this->asyncPaymentService->getSalesChannelContext(
                $context,
                $order->getSalesChannelId(),
                is_string($contextToken) ? $contextToken : ''
            );
            $this->updateCustomerIssuer($dataBag, $salesChannelContext);
        }
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
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        return [
            'issuer' => $dataBag->get('payBybankMethodId')
        ];
    }
    protected function updateCustomerIssuer(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $customer = $salesChannelContext->getCustomer();
        $lastPayByBank = $dataBag->get('payBybankMethodId');

        if (
            $customer !== null &&
            $lastPayByBank !== null &&
            $lastPayByBank !== $customer->getCustomFieldsValue(self::ISSUER_LABEL)
        ) {
            $this->customerService->updateCustomerData(
                $customer,
                $salesChannelContext->getContext(),
                [self::ISSUER_LABEL => $lastPayByBank]
            );
        }
    }
}
