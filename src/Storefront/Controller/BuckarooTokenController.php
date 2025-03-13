<?php

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;

/**
 * @RouteScope(scopes={"storefront"})
 */
class BuckarooTokenController extends AbstractPaymentController
{
    private HttpClientInterface $client;
    private LoggerInterface $logger;
    private SystemConfigService $systemConfigService;

    public function __construct(
        HttpClientInterface $client,
        LoggerInterface $logger,
        SystemConfigService $systemConfigService
    ) {
        $this->client = $client;
        $this->logger = $logger;
        $this->systemConfigService = $systemConfigService;
    }

    #[Route(path: '/buckaroo/get-oauth-token', options: ['seo' => false], methods: ['GET'], defaults: ['XmlHttpRequest' => true, '_routeScope' => ['storefront']])]
    public function getOAuthToken(Request $request): JsonResponse
    {
        // Validate Request Origin
        $requestOrigin = $request->headers->get('X-Requested-From');
        if ($requestOrigin !== 'ShopwareFrontend') {
            return new JsonResponse([
                'error'   => true,
                'message' => 'Unauthorized request'
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        // Retrieve credentials from Shopware settings
        $clientId = '994F5CDC62DE4E4283C4A11A3529B926';
        $clientSecret = '4A0077E31F1F4902A0CA02C8C61C52B5';
        $issuers = $this->systemConfigService->get('BuckarooPayments.config.allowedcreditcards');
        // Validate credentials
        if (empty($clientId) || empty($clientSecret)) {
            return new JsonResponse([
                'error'   => true,
                'message' => 'Hosted Fields Client ID or Secret is missing.'
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (empty($issuers)) {
            return new JsonResponse([
                'error'   => true,
                'message' => 'No Allowed Issuers configured for Hosted Fields.'
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            // Send request to Buckaroo API
            $response = $this->client->request('POST', 'https://auth.buckaroo.io/oauth/token', [
                'auth_basic' => [$clientId, $clientSecret],
                'headers'    => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'       => [
                    'scope'      => 'hostedfields:save',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $responseData = json_decode($response->getContent(), true);

            // Check if token exists
            if (isset($responseData['access_token'])) {
                return new JsonResponse([
                    'error' => false,
                    'data'  => [
                        'access_token' => $responseData['access_token'],
                        'expires_in'   => $responseData['expires_in'],
                        'issuers'      => $issuers
                    ]
                ]);
            }

            return new JsonResponse([
                'error'   => true,
                'message' => 'Failed to retrieve Buckaroo OAuth token.'
            ], JsonResponse::HTTP_BAD_REQUEST);

        } catch (\Exception $e) {
            $this->logger->error('Buckaroo Token Request Error: ' . $e->getMessage());

            return new JsonResponse([
                'error'   => true,
                'message' => 'An error occurred while fetching the Buckaroo token.'
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
