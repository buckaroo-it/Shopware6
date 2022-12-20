<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Handlers\AsyncPaymentHandler;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class PaypalPaymentHandler extends AsyncPaymentHandler
{

    protected string $paymentClass = Paypal::class;

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
        if ($dataBag->has('orderId')) {
            return ['payPalOrderId' => $dataBag->get('orderId')];
        }
        return [];
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $this->orderUpdater->update($response, $transaction->getOrder(), $salesChannelContext);
        
        return parent::handleResponse(
            $response,
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentCode
        );
    }
}
