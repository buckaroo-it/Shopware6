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
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

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
        // We dont really need this.
        
        // if ($dataBag->has('orderId')) {
        //     return ['payPalOrderId' => $dataBag->get('orderId')];
        // }

        if ($this->isSellerProtection($salesChannelContext)) {
            return $this->getSellerProtectionData($order);
        }
        return [];
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
        if ($this->isSellerProtection($salesChannelContext) && !$dataBag->has('orderId')) {
            return 'extraInfo';
        }
        return 'pay';
    }


    protected function handleResponse(
        ClientResponseInterface $response,
        $orderTransaction,
        $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $this->orderUpdater->update($response, $order, $salesChannelContext);

        return parent::handleResponse(
            $response,
            $orderTransaction,
            $order,
            $dataBag,
            $salesChannelContext,
            $paymentCode
        );
    }

    /**
     * Get seller protection data
     *
     * @param OrderEntity $order
     *
     * @return array
     */
    private function getSellerProtectionData(OrderEntity $order): array
    {
        $address = $this->asyncPaymentService->getBillingAddress($order);

        $countryState = '';

        $countryStateEntity = $address->getCountryState();
        if ($countryStateEntity !== null) {
            $countryState =  $countryStateEntity->getName();
        }

        return [
            'customer' => [
                'name' => $address->getFirstName() . " " . $address->getLastName(),
            ],
            'address' => [
                'street'  =>  $address->getStreet(),
                'zipcode' =>  $address->getZipcode(),
                'city'    =>  $address->getCity(),
                'state'   =>  $countryState,
                'country' =>  $this->asyncPaymentService->getCountry($address)->getIso()
            ],
            'phone' => [
                'mobile' =>  (string)$address->getPhoneNumber(),
            ]
        ];
    }

    /**
     * Check if seller protection is enabled
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return bool
     */
    private function isSellerProtection(SalesChannelContext $salesChannelContext): bool
    {
        return $this->getSetting(
            'paypalSellerprotection',
            $salesChannelContext->getSalesChannelId()
        ) === true;
    }
}
