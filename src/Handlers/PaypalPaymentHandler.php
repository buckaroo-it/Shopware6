<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Helpers\Helper;
use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
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
     * @param Helper $helper
     * @param CheckoutHelper $checkoutHelper
     */
    public function __construct(
        Helper $helper,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        UpdateOrderWithPaypalExpressData $orderUpdater
        ) {
        $this->orderUpdater = $orderUpdater;
        parent::__construct($helper, $checkoutHelper, $logger);
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

        $gatewayInfo['additional'][] = $this->getSellerProtectionData(
            $transaction->getOrder(),
            $salesChannelContext,
            $dataBag
        );
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
    private function getSellerProtectionData(
        OrderEntity $order,
        SalesChannelContext $salesChannelContext,
        RequestDataBag $dataBag
    ): array
    {
        $isSellerProtectionEnabled = $this->checkoutHelper
        ->getSetting(
            'paypalSellerprotection',
            $salesChannelContext->getSalesChannelId()
        );

        if($isSellerProtectionEnabled !== true || $dataBag->has('orderId')) {
            return [];
        }

        /** @var \Shopware\Core\Checkout\Customer\CustomerEntity */
        $customer =  $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();

        if ($shippingAddress === null) {
            /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity|null */
            $shippingAddress = $this->checkoutHelper->getShippingAddress($order, $salesChannelContext);
        }

        $country = $this->checkoutHelper->getCountryCode($shippingAddress);

        $countryState = '';
        if ($shippingAddress->getCountryState() !== null) {
            $countryState = $shippingAddress->getCountryState()->getName();
        }

        return [
            $this->setParameter('Name', $shippingAddress->getFirstName()." ".$shippingAddress->getLastName()),
            $this->setParameter('Street1', $shippingAddress->getStreet()),
            $this->setParameter('CityName', $shippingAddress->getCity()),
            $this->setParameter('StateOrProvince', (string)$countryState),
            $this->setParameter('PostalCode', $shippingAddress->getZipcode()),
            $this->setParameter('Country', $country),
            $this->setParameter('AddressOverride', 'TRUE'),
        ];

        return [];
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

    /**
     * Format data as a array of parameters
     *
     * @param string $name
     * @param mixed $value
     *
     * @return array
     */
    protected function setParameter(
        string $name,
        $value
    ): array {
        return [
            "_" => $value,
            "Name" => $name,
        ];
    }
}
