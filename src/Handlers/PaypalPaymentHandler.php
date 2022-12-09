<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Handlers\AsyncPaymentHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class PaypalPaymentHandler extends AsyncPaymentHandler
{

    /**
     * @var \Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData
     */
    protected $orderUpdater;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        UpdateOrderWithPaypalExpressData $orderUpdater
        
        ) {
        parent::__construct($asyncPaymentService);
        $this->orderUpdater = $orderUpdater;
    }


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
        $paymentMethod = new Paypal();
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
    /**
    * Handle pay response from buckaroo
    *
    * @param TransactionResponse $response
    * @param OrderEntity $order
    * @param SalesChannelContext $saleChannelContext
    *
    * @return void
    */
    protected function handlePayResponse(
        TransactionResponse $response,
        OrderEntity $order,
        SalesChannelContext $saleChannelContext
    ): void
    {
        $this->orderUpdater->update($response, $order, $saleChannelContext);
    }
}
