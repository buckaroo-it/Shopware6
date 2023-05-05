<?php

declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

use Symfony\Component\HttpFoundation\Request;


class SignatureValidationService
{
    protected SettingsService $settingsService;
    
    public function __construct(SettingsService $settingsService) {
        $this->settingsService = $settingsService;
    }
    
    /**
     * Generate/calculate the signature with the buckaroo config value and check if thats equal to the signature
     * received from the push
     *
     * @return bool
     */
    public function validateSignature(Request $request, string $salesChannelId = null)
    {
        $postData = $request->request->all();

        if (!isset($postData['brq_signature'])) {
            return false;
        }

        $signature = $this->calculateSignature($postData, $salesChannelId);

        if ($signature !== $postData['brq_signature']) {
            return false;
        }

        return true;
    }

    public function calculatePushHash($postData)
    {
        $copyData = $postData;
        unset($copyData['brq_signature']);
        unset($copyData['brq_timestamp']);
        unset($copyData['brq_customer_name']);

        $sortableArray = $this->buckarooArraySort($copyData);

        $calculatedString = Date("YmdHi");
        foreach ($sortableArray as $brq_key => $value) {
            $value = $this->decodePushValue($brq_key, $value);
            $calculatedString .= $brq_key . '=' . $value;
        }

        return SHA1($calculatedString);
    }

    /**
     * Determines the signature using array sorting and the SHA1 hash algorithm
     *
     * @param $postData
     *
     * @return string
     */
    protected function calculateSignature($postData, string $salesChannelId = null)
    {
        $copyData = $postData;
        unset($copyData['brq_signature']);

        $sortableArray = $this->buckarooArraySort($copyData);

        $signatureString = '';

        foreach ($sortableArray as $brq_key => $value) {
            $value = $this->decodePushValue($brq_key, $value);

            $signatureString .= $brq_key . '=' . $value;
        }

        $signatureString .= $this->settingsService->getSetting('secretKey', $salesChannelId);

        $signature = SHA1($signatureString);

        return $signature;
    }

    

    /**
     * @param string $brq_key
     * @param string $brq_value
     *
     * @return string
     */
    private function decodePushValue($brq_key, $brq_value)
    {
        switch (strtolower($brq_key)) {
            case 'brq_customer_name':
            case 'brq_service_ideal_consumername':
            case 'brq_service_transfer_consumername':
            case 'brq_service_payconiq_payconiqandroidurl':
            case 'brq_service_paypal_payeremail':
            case 'brq_service_paypal_payerfirstname':
            case 'brq_service_paypal_payerlastname':
            case 'brq_service_payconiq_payconiqiosurl':
            case 'brq_service_payconiq_payconiqurl':
            case 'brq_service_payconiq_qrurl':
            case 'brq_service_masterpass_customerphonenumber':
            case 'brq_service_masterpass_shippingrecipientphonenumber':
            case 'brq_invoicedate':
            case 'brq_duedate':
            case 'brq_previousstepdatetime':
            case 'brq_eventdatetime':
            case 'brq_service_transfer_accountholdername':
            case 'brq_service_transfer_customeraccountname':
            case 'cust_customerbillingfirstname':
            case 'cust_customerbillinglastname':
            case 'cust_customerbillingemail':
            case 'cust_customerbillingstreet':
            case 'cust_customerbillingtelephone':
            case 'cust_customerbillinghousenumber':
            case 'cust_customerbillinghouseadditionalnumber':
            case 'cust_customershippingfirstname':
            case 'cust_customershippinglastname':
            case 'cust_customershippingemail':
            case 'cust_customershippingstreet':
            case 'cust_customershippingtelephone':
            case 'cust_customershippinghousenumber':
            case 'cust_customershippinghouseadditionalnumber':
            case 'cust_mailadres':
            case 'brq_description':
                $decodedValue = $brq_value;
                break;
            default:
                $decodedValue = urldecode($brq_value);
        }

        return $decodedValue;
    }

    /**
     * Sort the array so that the signature can be calculated identical to the way buckaroo does.
     *
     * @param $arrayToUse
     *
     * @return array $sortableArray
     */
    protected function buckarooArraySort($arrayToUse)
    {
        $arrayToSort   = [];
        $originalArray = [];

        foreach ($arrayToUse as $key => $value) {
            $arrayToSort[strtolower($key)]   = $value;
            $originalArray[strtolower($key)] = $key;
        }

        ksort($arrayToSort);

        $sortableArray = [];

        foreach ($arrayToSort as $key => $value) {
            $key                 = $originalArray[$key];
            $sortableArray[$key] = $value;
        }

        return $sortableArray;
    }
}
