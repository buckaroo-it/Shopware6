<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\PayPerEmail;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class PayPerEmailPaymentHandler extends AsyncPaymentHandler
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $buckarooKey
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $buckarooKey = null,
        string $type = null,
        string $version = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $dataBag = $this->getRequestBag($dataBag);
        $paymentMethod = new PayPerEmail();

        $gatewayInfo['additional'][] = [
            [
                'Name' => 'CustomerGender',
                '_' => $dataBag->get('buckaroo_payperemail_CustomerGender'),
            ],
            [
                'Name' => 'CustomerEmail',
                '_' => $dataBag->get('buckaroo_payperemail_CustomerEmail'),
            ],
            [
                'Name' => 'CustomerFirstName',
                '_' => $dataBag->get('buckaroo_payperemail_CustomerFirstName'),
            ],
            [
                'Name' => 'CustomerLastName',
                '_' => $dataBag->get('buckaroo_payperemail_CustomerLastName'),
            ],
            [
                '_'    => $this->checkoutHelper->getPayPerEmailPaymentMethodsAllowed($this->salesChannelContext->getSalesChannelId()),
                'Name' => 'PaymentMethodsAllowed',
            ],
        ];

        if($payperemailExpireDays = $this->checkoutHelper->getSettingsValue('payperemailExpireDays', $this->salesChannelContext->getSalesChannelId())){
            $gatewayInfo['additional'][] = [[
                'Name' => 'ExpirationDate',
                '_' => date('Y-m-d', time() + $payperemailExpireDays * 86400),
            ]];
        }

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getBuckarooKey(),
            $paymentMethod->getType(),
            $paymentMethod->getVersion(),
            $gatewayInfo
        );
    }

}
