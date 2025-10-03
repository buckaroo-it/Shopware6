<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\PaymentMethods\PayPerEmail;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class PayPerEmailPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = PayPerEmail::class;

    protected PayLinkService $payLinkService;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        PayLinkService $payLinkService
    ) {
        parent::__construct($asyncPaymentService);
        $this->payLinkService = $payLinkService;
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
        $address  = $this->asyncPaymentService->getBillingAddress($order);
        $salesChannelContextId =  $salesChannelContext->getSalesChannelId();
        return [
            'email' => $dataBag->get(
                'buckaroo_payperemail_CustomerEmail',
                $this->asyncPaymentService->getCustomer($order)->getEmail()
            ),
            'customer'  => [
                'firstName'     =>  $dataBag->get(
                    'buckaroo_payperemail_CustomerFirstName',
                    $address->getFirstName()
                ),
                'lastName'      => $dataBag->get(
                    'buckaroo_payperemail_CustomerLastName',
                    $address->getLastName()
                ),
                'gender' => $dataBag->get('buckaroo_payperemail_gender'),

            ],
            'expirationDate'        => $this->getExpirationDate($salesChannelContextId),
            'paymentMethodsAllowed' => $this->payLinkService
                ->getPayPerEmailPaymentMethodsAllowed(
                    $salesChannelContextId
                ),
            'additionalParameters' => [
                'fromPayPerEmail' => 1
            ]

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
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'paymentInvitation';
    }

    private function getExpirationDate(string $salesChannelContextId): string
    {
        $payperemailExpireDays = $this->getSetting(
            'payperemailExpireDays',
            $salesChannelContextId
        );

        if (!is_scalar($payperemailExpireDays)) {
            return '';
        }

        return date('Y-m-d', time() + (int)$payperemailExpireDays * 86400);
    }
}
