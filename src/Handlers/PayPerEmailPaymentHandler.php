<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\PaymentMethods\PayPerEmail;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class PayPerEmailPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = PayPerEmail::class;

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
        $address  = $order->getBillingAddress();
        $salesChannelContextId =  $salesChannelContext->getSalesChannelId();
        return [
            'email' => $dataBag->get(
                'buckaroo_payperemail_CustomerEmail',
                $order->getOrderCustomer()->getEmail()
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
            'paymentMethodsAllowed' => $this->payLinkService->getPayPerEmailPaymentMethodsAllowed($salesChannelContextId),
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
    protected function getMethodAction(
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): string {
        return 'paymentInvitation';
    }

    private function getExpirationDate(string $salesChannelContextId)
    {
        $payperemailExpireDays = $this->getSetting(
            'payperemailExpireDays',
            $salesChannelContextId
        );

        return date('Y-m-d', time() + (int)$payperemailExpireDays * 86400);
    }
}
