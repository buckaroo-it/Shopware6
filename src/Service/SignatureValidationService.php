<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Config\DefaultConfig;
use Buckaroo\Handlers\Reply\ReplyHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class SignatureValidationService
{
    protected SettingsService $settingsService;

    protected LoggerInterface $logger;

    public function __construct(SettingsService $settingsService, LoggerInterface $logger)
    {
        $this->settingsService = $settingsService;
        $this->logger = $logger;
    }

    /**
     * Validate the Buckaroo push signature.
     *
     * Validation is delegated to the Buckaroo PHP SDK (>= 1.24.5), which is the single
     * source of truth for the push signature algorithm. Besides recalculating the SHA1
     * over the signed fields, the SDK rejects requests that try to smuggle the signature
     * through case-variant field names and compares hashes in constant time.
     *
     * @return bool
     */
    public function validateSignature(Request $request, ?string $salesChannelId = null): bool
    {
        return $this->validateData($request->request->all(), $salesChannelId);
    }

    /**
     * Validate the Buckaroo signature of a shopper returning from Buckaroo. Buckaroo sends
     * the return fields either in the body (POST) or in the query string (GET).
     */
    public function validateReturnSignature(Request $request, ?string $salesChannelId = null): bool
    {
        $data = $request->request->has('brq_signature')
            ? $request->request->all()
            : $request->query->all();

        return $this->validateData($data, $salesChannelId);
    }

    /**
     * The push fields with the values the signature was validated over. Values are decoded
     * the way Buckaroo signed them, so two encodings of the same signed value are equal.
     *
     * @return array<mixed>
     */
    public function getSignedPushData(Request $request): array
    {
        return $this->normalizeSignedData($request->request->all());
    }

    /**
     * @param array<mixed> $postData
     */
    private function validateData(array $postData, ?string $salesChannelId): bool
    {
        if (!isset($postData['brq_signature']) || !is_string($postData['brq_signature'])) {
            return false;
        }

        try {
            $secretKey = $this->settingsService->getSetting('secretKey', $salesChannelId);

            // A secret key is required to validate the push.
            if (!is_string($secretKey) || trim($secretKey) === '') {
                $this->logger->warning(
                    'Buckaroo push rejected: no secret key is configured for sales channel ' .
                    ($salesChannelId ?? 'default')
                );

                return false;
            }

            $replyHandler = new ReplyHandler(
                new DefaultConfig(
                    '',
                    $secretKey
                ),
                $this->normalizeSignedData($postData)
            );

            $replyHandler->validate();

            return $replyHandler->isValid();
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Buckaroo push signature validation failed: ' . $exception->getMessage()
            );

            return false;
        }
    }

    /**
     * Restore the fields to the form Buckaroo signed them in before the SDK hashes them:
     * PHP replaces spaces in field names with underscores, and Buckaroo signs most values
     * url-decoded, while the SDK only decodes HTML entities.
     *
     * @param array<mixed> $postData
     *
     * @return array<mixed>
     */
    private function normalizeSignedData(array $postData): array
    {
        $normalized = [];

        foreach ($postData as $key => $value) {
            $key = $this->getCorrectKey((string)$key);

            if (is_string($value)) {
                $value = $this->decodePushValue($key, $value);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function getCorrectKey(string $key): string
    {
        if ($key === 'brq_SERVICE_boekenbon_Additional_Info') {
            $key = 'brq_SERVICE_boekenbon_Additional Info';
        }

        return $key;
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
            case 'brq_invoicepaylink':
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
}
