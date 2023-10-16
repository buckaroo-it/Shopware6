<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Symfony\Component\HttpFoundation\Request;

class SignatureValidationService
{
    protected SettingsService $settingsService;

    public function __construct(SettingsService $settingsService)
    {
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

        dd($postData, $signature ,$postData['brq_signature']);
        
        if ($signature !== $postData['brq_signature']) {
            return false;
        }

        return true;
    }
    /**
     * @param array<mixed> $postData
     *
     * @return string
     */
    public function calculatePushHash(array $postData): string
    {
        $copyData = $postData;
        unset($copyData['brq_signature']);
        unset($copyData['brq_timestamp']);
        unset($copyData['brq_customer_name']);

        $sortableArray = $this->buckarooArraySort($copyData);

        $calculatedString = Date("YmdHi");
        foreach ($sortableArray as $brq_key => $value) {
            if (is_scalar($value)) {
                $value = $this->decodePushValue($brq_key, (string)$value);
                $calculatedString .= $brq_key . '=' . $value;
            }
        }

        return SHA1($calculatedString);
    }

    /**
     * Determines the signature using array sorting and the SHA1 hash algorithm
     *
     * @param array<mixed> $postData
     *
     * @return string
     */
    protected function calculateSignature(array $postData, string $salesChannelId = null): string
    {
        $copyData = $postData;
        unset($copyData['brq_signature']);

        $sortableArray = $this->buckarooArraySort($copyData);

        $signatureString = '';

        foreach ($sortableArray as $brq_key => $value) {
            if (is_scalar($value)) {
                $value = $this->decodePushValue($brq_key, (string)$value);
                $signatureString .= $brq_key . '=' . $value;
            }
        }

        $signatureString .= $this->settingsService->getSetting('secretKey', $salesChannelId);

        return SHA1($signatureString);
    }



    /**
     * @param string $brq_key
     * @param string $brq_value
     *
     * @return string
     */
    private function decodePushValue($brq_key, $brq_value)
    {
        $fields = [
            'brq_customer_name',
            'brq_service_ideal_consumername',
            'brq_service_transfer_consumername',
            'brq_service_payconiq_payconiqandroidurl',
            'brq_service_paypal_payeremail',
            'brq_service_paypal_payerfirstname',
            'brq_service_paypal_payerlastname',
            'brq_service_payconiq_payconiqiosurl',
            'brq_service_payconiq_payconiqurl',
            'brq_service_payconiq_qrurl',
            'brq_service_masterpass_customerphonenumber',
            'brq_service_masterpass_shippingrecipientphonenumber',
            'brq_service_transfer_accountholdername',
            'brq_service_transfer_customeraccountname',
            'cust_customerbillingfirstname',
            'cust_customerbillinglastname',
            'cust_customerbillingemail',
            'cust_customerbillingstreet',
            'cust_customerbillingtelephone',
            'cust_customerbillinghousenumber',
            'cust_customerbillinghouseadditionalnumber',
            'cust_customershippingfirstname',
            'cust_customershippinglastname',
            'cust_customershippingemail',
            'cust_customershippingstreet',
            'cust_customershippingtelephone',
            'cust_customershippinghousenumber',
            'cust_customershippinghouseadditionalnumber',
            'cust_mailadres',
            'brq_description',
            'brq_invoicedate',
            'brq_duedate',
            'brq_previousstepdatetime',
            'brq_invoicepaylink',
            'brq_eventdatetime'
        ];

        if (in_array(strtolower($brq_key), $fields)) {
                return $brq_value;
        }

        return urldecode($brq_value);
    }

    /**
     * Sort the array so that the signature can be calculated identical to the way buckaroo does.
     *
     * @param array<mixed> $arrayToUse
     *
     * @return array<mixed> $sortableArray
     */
    protected function buckarooArraySort(array $arrayToUse): array
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
