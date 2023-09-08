<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Refund;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Buckaroo\Refund\RefundDataInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;

class Builder
{
    protected ClientService $clientService;

    protected SettingsService $settingsService;

    protected UrlService $urlService;

    public function __construct(
        ClientService $clientService,
        SettingsService $settingsService,
        UrlService $urlService
    ) {
        $this->clientService = $clientService;
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
    }

    public function build(
        RefundDataInterface $refundData,
        Request $request,
        string $configCode
    ): Client {
        $paymentRecord = $refundData->getPaymentRecord();
        $transactionMethod = $paymentRecord->getPaymentCode();

        $client = $this->getClient(
            $configCode,
            $refundData->getOrder()->getSalesChannelId()
        )
            ->setAction('refund')
            ->setPayload(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $refundData,
                        $paymentRecord->getOriginalTransactionId(),
                    ),
                    $this->getMethodPayload(
                        $refundData->getAmount(),
                        $configCode,
                        $transactionMethod
                    )
                )
            );

        if (
            $configCode === 'giftcards' && $transactionMethod !== null
        ) {
            $client->setPaymentCode($transactionMethod);
        }

        //Override payByBank if transaction was made with ideal
        if ($configCode === 'paybybank' && $transactionMethod === 'ideal') {
            $client->setPaymentCode($transactionMethod);
        }

        return $client;
    }

    /**
     * Get parameters common to all payment methods
     *
     * @param Request $request
     * @param RefundDataInterface $refundData
     * @param mixed $transactionKey
     *
     * @return array<mixed>
     */
    private function getCommonRequestPayload(
        Request $request,
        RefundDataInterface $refundData,
        $transactionKey,
    ): array {

        if (!is_scalar($transactionKey)) {
            $transactionKey = '';
        }

        return [
            'order'                  => $refundData->getInvoiceNumber(),
            'invoice'                => $refundData->getOrderNumber(),
            'amountCredit'           => $refundData->getAmount(),
            'currency'               => $refundData->getCurrency(),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'pushURLFailure'         => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'originalTransactionKey' => (string)$transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $refundData->getTransactionId(),
                'orderId' => $refundData->getOrderId(),
            ],
        ];
    }

    /**
     * Get method specific payloads
     *
     * @param float $amount
     * @param string $configCode
     * @param string|null $transactionMethod
     *
     * @return array<mixed>
     */
    private function getMethodPayload(
        float $amount,
        string $configCode,
        string $transactionMethod = null
    ): array {
        if (
            $configCode === "afterpay" &&
            $this->settingsService->getSetting('afterpayEnabledold') === true
        ) {
            return $this->getRefundRequestArticlesForAfterpayOld($amount);
        }

        if (in_array($configCode, ["afterpay", "Billink", "klarnakp"])) {
            return $this->getRefundArticleData($amount);
        }

        if (
            in_array($configCode, ['creditcard', 'creditcards', 'giftcards']) &&
            $transactionMethod !== null
        ) {
            return [
                "name" => $transactionMethod,
                "version" => 2
            ];
        }

        return [];
    }

    /**
     * Get buckaroo client
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return Client
     */
    private function getClient(string $paymentCode, string $salesChannelId): Client
    {
        return $this->clientService
            ->get($paymentCode, $salesChannelId);
    }

    /**
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function getRefundArticleData(float $amount): array
    {

        return [
            'articles' => [[
                'refundType'        => 'Return',
                'identifier'        => 1,
                'description'       => 'Refund',
                'quantity'          => 1,
                'price'             =>  round($amount, 2),
                'vatPercentage'     => 0,
            ]]
        ];
    }

    /**
     * @param float $amount
     *
     * @return array<mixed>
     */
    private function getRefundRequestArticlesForAfterpayOld(float $amount): array
    {

        return [
            'articles' => [[
                'identifier'   => 1,
                'description'  => 'Refund',
                'quantity'     => 1,
                'price'        => round($amount, 2),
                'vatCategory'  => 4,
            ]]
        ];
    }

    /**
     * Get client ip
     *
     * @param Request $request
     *
     * @return array<mixed>
     */
    private function getIp(Request $request): array
    {
        $remoteIp = $request->getClientIp();

        return [
            'address' =>  $remoteIp,
            'type'    => IPProtocolVersion::getVersion($remoteIp)
        ];
    }
}
