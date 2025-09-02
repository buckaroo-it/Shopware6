<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers\Payments\Legacy;

use Buckaroo\Shopware6\PaymentMethods\Paypal;
use Buckaroo\Shopware6\Handlers\PaymentHandlerLegacy;
use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;

class PaypalPaymentHandlerLegacy extends PaymentHandlerLegacy
{
    protected string $paymentClass = Paypal::class;

    /**
     * @var \Buckaroo\Shopware6\Service\UpdateOrderWithPaypalExpressData
     */
    protected $orderUpdater;

    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        UpdateOrderWithPaypalExpressData $orderUpdater
    ) {
        parent::__construct($asyncPaymentService);
        $this->orderUpdater = $orderUpdater;
    }

    protected function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        if ($this->isSellerProtection($salesChannelContext)) {
            return $this->getSellerProtectionData($order);
        }
        return [];
    }

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
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): RedirectResponse {
        $order = $transaction->getOrder();
        if ($order instanceof OrderEntity) {
            $this->orderUpdater->update($response, $order, $salesChannelContext);
        }

        return parent::handleResponse(
            $response,
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentCode
        );
    }

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

    private function isSellerProtection(SalesChannelContext $salesChannelContext): bool
    {
        return $this->getSetting(
            'paypalSellerprotection',
            $salesChannelContext->getSalesChannelId()
        ) === true;
    }
}


