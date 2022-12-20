<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\SepaDirectDebit;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SepaDirectDebitPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = SepaDirectDebit::class;

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

        if(
            $dataBag->has('buckarooSepaDirectDebitIBAN') &&
            $dataBag->has('buckarooSepaDirectDebitCustomer')
        ) {
            return [
                'iban'              => $dataBag->get('buckarooSepaDirectDebitIBAN'),
                'customer'      => [
                    'name'          => $dataBag->get('buckarooSepaDirectDebitCustomer')
                ]
            ];
        }
        return [];
    }
}
