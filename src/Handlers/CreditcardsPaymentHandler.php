<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Creditcards;

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CreditcardsPaymentHandler extends AsyncPaymentHandler
{
    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $paymentMethod = new Creditcards();
        $gatewayInfo = [
            'key' =>  $paymentMethod->getBuckarooKey(),
            'version' =>  $paymentMethod->getVersion(),
            'refund' =>  $paymentMethod->canRefund(),
        ];
        if ($dataBag->get('creditcard')) {
            $gatewayInfo['creditcard'] = $dataBag->get('creditcard');
        }
        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getGatewayCode(),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
