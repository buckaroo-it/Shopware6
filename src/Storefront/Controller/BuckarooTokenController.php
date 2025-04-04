<?php

namespace Buckaroo\Shopware6\Storefront\Controller;

use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

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

        $requestOrigin = $request->headers->get('X-Requested-From');
        if ($requestOrigin !== 'ShopwareFrontend') {
            return new JsonResponse([
                'error'   => true,
                'message' => 'Unauthorized request'
            ], JsonResponse::HTTP_FORBIDDEN);
        }



        $clientId =  $this->systemConfigService->get('BuckarooPayments.config.clientId');
        $clientSecret = $this->systemConfigService->get('BuckarooPayments.config.clientSecret');
        $issuers = $this->systemConfigService->get('BuckarooPayments.config.allowedcreditcards');

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
            $response = $this->client->request('POST', 'https://auth.buckaroo.io/oauth/token', [
                'auth_basic' => [$clientId, $clientSecret],
                'headers'    => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body'       => [
                    'scope'      => 'hostedfields:save',
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $responseData = json_decode($response->getContent(), true);

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
