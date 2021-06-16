<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Handlers;

use Buckaroo\Shopware6\PaymentMethods\In3;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class In3PaymentHandler extends AsyncPaymentHandler
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
    ): RedirectResponse{

        $order      = $transaction->getOrder();
        $paymentMethod = new In3();
        $gatewayInfo   = [
            'additional' => [$this->getIn3Data($order, $salesChannelContext, $dataBag)]
        ];

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

    public function getIn3Data($order, $salesChannelContext, $dataBag){
        $now = new \DateTime();

        $address  = $this->checkoutHelper->getBillingAddress($order, $salesChannelContext);
        $customer = $this->checkoutHelper->getOrderCustomer($order, $salesChannelContext);
        $streetData  = $this->checkoutHelper->formatStreet($address->getStreet());

        $requestParameter = [
            $this->checkoutHelper->getRequestParameterRow($dataBag->get('buckaroo_capayablein3_orderAs'), 'CustomerType'),
            $this->checkoutHelper->getRequestParameterRow($now->format('Y-m-d'), 'InvoiceDate'),
            $this->checkoutHelper->getRequestParameterRow($dataBag->get('buckaroo_in3_phone'), 'Phone', 'Phone'),
            $this->checkoutHelper->getRequestParameterRow($customer->getEmail(), 'Email', 'Email'),

            $this->checkoutHelper->getRequestParameterRow($this->checkoutHelper->getInitials($address->getFirstName()), 'Initials', 'Person'),
            $this->checkoutHelper->getRequestParameterRow($address->getLastName(), 'LastName', 'Person'),
            $this->checkoutHelper->getRequestParameterRow('nl-NL', 'Culture', 'Person'),
            $this->checkoutHelper->getRequestParameterRow($this->checkoutHelper->getGenderFromSalutation($customer), 'Gender', 'Person'),
            $this->checkoutHelper->getRequestParameterRow($dataBag->get('buckaroo_capayablein3_DoB'), 'BirthDate', 'Person'),
            
            $this->checkoutHelper->getRequestParameterRow($streetData['street'], 'Street', 'Address'),
            $this->checkoutHelper->getRequestParameterRow($streetData['house_number']?$streetData['house_number']:$address->getAdditionalAddressLine1(), 'HouseNumber', 'Address'),
            $this->checkoutHelper->getRequestParameterRow($address->getZipCode(), 'ZipCode', 'Address'),
            $this->checkoutHelper->getRequestParameterRow($address->getCity(), 'City', 'Address'),
            $this->checkoutHelper->getRequestParameterRow((($address->getCountry() && $address->getCountry()->getIso()) ? $address->getCountry()->getIso() : 'NL'), 'Country', 'Address')
        ];

        if (strlen($streetData['number_addition']) > 0) {
            $param = $this->checkoutHelper->getRequestParameterRow($streetData['number_addition'], 'HouseNumberSuffix', 'Address');
            $requestParameter[] = $param;
        }

        if(in_array($dataBag->get('buckaroo_capayablein3_orderAs'),[1,2])){
            $requestParameter[] = $this->checkoutHelper->getRequestParameterRow($dataBag->get('buckaroo_capayablein3_orderAs'), 'Name', 'Company');
            $requestParameter[] = $this->checkoutHelper->getRequestParameterRow($dataBag->get('buckaroo_capayablein3_COCNumber'), 'ChamberOfCommerce', 'Company');
        }
        $requestParameter = array_merge($requestParameter, $this->checkoutHelper->getProductLineData($order));

        return $requestParameter;
    }
}
