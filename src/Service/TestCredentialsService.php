<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\SettingsService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;

class TestCredentialsService
{
    protected SettingsService $settingsService;

    protected UrlService $urlService;

    protected ClientService $clientService;

    protected TranslatorInterface $translator;

    public function __construct(
        SettingsService $settingsService,
        UrlService $urlService,
        ClientService $clientService,
        TranslatorInterface $translator
    ) {
        $this->settingsService = $settingsService;
        $this->urlService = $urlService;
        $this->clientService = $clientService;
        $this->translator = $translator;
    }
    /**
     *
     * @param Request $request
     *
     * @return array<mixed>
     */
    public function execute(Request $request): array
    {
        $salesChannelId = $request->get('saleChannelId');

        if (!(is_string($salesChannelId) || is_null($salesChannelId))) {
            return [
                'status' => 'error',
                'message' => $this->translator->trans("buckaroo-payment.test_api.connection_failed"),
            ];
        }

        $websiteKeyId = $request->get('websiteKeyId');
        $secretKeyId = $request->get('secretKeyId');

        if (!is_scalar($websiteKeyId) || !is_scalar($secretKeyId)) {
            return [
                'status' => 'error',
                'message' => $this->translator->trans("buckaroo-payment.test_api.connection_failed"),
            ];
        }

        $this->settingsService->setSetting(
            'websiteKey',
            (string)$websiteKeyId,
            $salesChannelId
        );
        $this->settingsService->setSetting(
            'secretKey',
            (string)$secretKeyId,
            $salesChannelId
        );

        $client = $this->getClientService(
            'ideal',
            $salesChannelId
        );

        try {
            $client->execute([
                'clientIP' => $this->getIp($request),
            ]);
            return [
                'status' => 'success',
                'message' => $this->translator->trans("buckaroo-payment.test_api.connection_ready"),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $this->translator->trans("buckaroo-payment.test_api.connection_failed"),
            ];
        }
    }
    /**
     * Get buckaroo client
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return Client
     */
    private function getClientService(string $paymentCode, string $salesChannelId = null): Client
    {
        return $this->clientService
            ->get($paymentCode, $salesChannelId);
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
            'address'       =>  $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }
}
