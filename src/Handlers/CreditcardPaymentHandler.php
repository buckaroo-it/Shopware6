<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\CustomerService;
use Buckaroo\Shopware6\PaymentMethods\Creditcard;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;

class CreditcardPaymentHandler extends AsyncPaymentHandler
{
    protected string $paymentClass = Creditcard::class;

    public const ISSUER_LABEL = 'last_used_creditcard';

    protected CustomerService $customerService;

    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        CustomerService $customerService
    ) {
        parent::__construct($asyncPaymentService);
        $this->customerService = $customerService;
    }


     /**
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @return RedirectResponse
     * @throws PaymentException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $dataBag = new RequestDataBag($request->request->all());
        $this->updateCustomerIssuer($dataBag, $this->asyncPaymentService->getSalesChannelContext($context));
        return parent::pay($request, $transaction, $context, $validateStruct);
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
        if ($dataBag->has('creditcard')) {
            return [
                'name' => $dataBag->get('creditcard'),
            ];
        }
        return [];
    }

    protected function updateCustomerIssuer(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $customer = $salesChannelContext->getCustomer();
        $issuer = $dataBag->get('creditcard');
        if (
            $customer !== null &&
            $issuer !== null &&
            $issuer !== $customer->getCustomFieldsValue(self::ISSUER_LABEL)
        ) {
            $this->customerService->updateCustomerData(
                $customer,
                $salesChannelContext->getContext(),
                [self::ISSUER_LABEL => $issuer]
            );
        }
    }
}
