<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\UrlService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;

class TestCredentialsService
{
    protected SettingsService $settingsService;

    protected UrlService $urlService;

    protected BkrClient $client;

    protected TranslatorInterface $translator;

    public function __construct(
        SettingsService $settingsService,
        UrlService $urlService,
        BkrClient $client,
        TranslatorInterface $translator
    ) {
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->client = $client;
        $this->translator = $translator;
    }


    public function getBuckarooApiTest(Request $clientRequest)
    {
        $salesChannelId = $clientRequest->get('saleChannelId');

        $this->settingsService->setSetting(
            'websiteKey',
            (string)$clientRequest->get('websiteKeyId'),
            $salesChannelId
        );
        $this->settingsService->setSetting(
            'secretKey',
            (string)$clientRequest->get('secretKeyId'),
            $salesChannelId
        );

        $request = new TransactionRequest;
        $request->setClientIPAndAgent($clientRequest);
        $request->setServiceName('ideal');
        $request->setServiceVersion('2');

        $url = $this->urlService->getTransactionUrl('ideal', $salesChannelId);
        $this->client->setSalesChannelId($salesChannelId);

        try {
            $response  = $this->client->post($url, $request);
            if ($response->getHttpCode() == '200') {
                return [
                    'status' => 'success',
                    'message' => $this->translator->trans("buckaroo-payment.test_api.connection_ready"),
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $this->translator->trans("buckaroo-payment.test_api.connection_failed"),
            ];
        }
    }
}
