<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Framework\Uuid\Uuid;
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

        if (!(is_null($salesChannelId) || (is_string($salesChannelId) && Uuid::isValid($salesChannelId)))) {
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

        try {
            // test with the submitted credentials only, nothing is persisted
            $response = $this->clientService
                ->getWithCredentials(
                    'ideal',
                    (string)$websiteKeyId,
                    (string)$secretKeyId,
                    $salesChannelId
                )
                ->setPayload([
                    'clientIP' => $this->getIp($request),
                ])
                ->execute();

            if (!$this->isAuthenticated($response)) {
                return [
                    'status' => 'error',
                    'message' => $this->translator->trans("buckaroo-payment.test_api.connection_failed"),
                ];
            }

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
     * The test request is incomplete on purpose, so a validation failure is expected;
     * the credentials are accepted when Buckaroo answers with 2xx and a transaction status
     *
     * @param ClientResponseInterface $response
     *
     * @return bool
     */
    private function isAuthenticated(ClientResponseInterface $response): bool
    {
        $httpStatusCode = $response->getHttpStatusCode();

        return $httpStatusCode !== null &&
            $httpStatusCode >= 200 &&
            $httpStatusCode < 300 &&
            $response->getStatusCode() !== null;
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
