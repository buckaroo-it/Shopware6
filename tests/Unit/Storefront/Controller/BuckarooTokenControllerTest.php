<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Storefront\Controller\BuckarooTokenController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Hosted Fields OAuth token also carries the services the SDK may accept.
 * Cards get the configured issuers; Bancontact gets its own service, which the
 * SDK refuses to combine with card issuers, and does not depend on them.
 *
 * The Hosted Fields client ID, secret and allowed cards can be set per sales channel,
 * so they must be read for the sales channel of the storefront request.
 */
class BuckarooTokenControllerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    public function testHostedFieldsSettingsAreReadForTheSalesChannel(): void
    {
        $requested = [];
        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')->willReturnCallback(
            static function (string $key, ?string $salesChannelId) use (&$requested) {
                $requested[$key] = $salesChannelId;
                // No allowed cards, so the controller stops before calling Buckaroo
                return str_ends_with($key, 'allowedcreditcards') ? [] : 'value';
            }
        );

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $controller = new BuckarooTokenController(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $systemConfigService
        );
        $response = $controller->getOAuthToken($this->createRequest([]), $this->createSalesChannelContext());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([
            'BuckarooPayments.config.clientId' => self::SALES_CHANNEL_ID,
            'BuckarooPayments.config.clientSecret' => self::SALES_CHANNEL_ID,
            'BuckarooPayments.config.allowedcreditcards' => self::SALES_CHANNEL_ID,
        ], $requested);
    }

    public function testCardsReceiveTheAllowedCreditCards(): void
    {
        $data = $this->requestToken([], ['MasterCard', 'Visa']);

        $this->assertSame(['MasterCard', 'Visa'], $data['data']['issuers']);
    }

    public function testBancontactReceivesOnlyTheBancontactService(): void
    {
        $data = $this->requestToken(['paymentMethod' => 'bancontactmrcash'], ['MasterCard', 'Visa']);

        $this->assertSame(['BancontactMrCash'], $data['data']['issuers']);
    }

    public function testBancontactDoesNotRequireAllowedCreditCards(): void
    {
        $data = $this->requestToken(['paymentMethod' => 'bancontactmrcash'], null);

        $this->assertFalse($data['error']);
        $this->assertSame(['BancontactMrCash'], $data['data']['issuers']);
    }

    public function testCardsStillRequireAllowedCreditCards(): void
    {
        $httpClient = new MockHttpClient();

        $response = $this->createController(null, $httpClient)
            ->getOAuthToken($this->createRequest([]), $this->createSalesChannelContext());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame(0, $httpClient->getRequestsCount());
    }

    /**
     * @param array<string, string> $query
     * @param array<string>|null $allowedCreditCards
     *
     * @return array<string, mixed>
     */
    private function requestToken(array $query, ?array $allowedCreditCards): array
    {
        $httpClient = new MockHttpClient(
            new MockResponse((string) json_encode(['access_token' => 'access-token', 'expires_in' => 600]))
        );

        $response = $this->createController($allowedCreditCards, $httpClient)
            ->getOAuthToken($this->createRequest($query), $this->createSalesChannelContext());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(1, $httpClient->getRequestsCount());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true);

        return $data;
    }

    /**
     * @param array<string>|null $allowedCreditCards
     */
    private function createController(?array $allowedCreditCards, MockHttpClient $httpClient): BuckarooTokenController
    {
        $config = [
            'BuckarooPayments.config.clientId' => 'client-id',
            'BuckarooPayments.config.clientSecret' => 'client-secret',
            'BuckarooPayments.config.allowedcreditcards' => $allowedCreditCards,
        ];

        $systemConfigService = $this->createMock(SystemConfigService::class);
        $systemConfigService->method('get')
            ->willReturnCallback(fn (string $key) => $config[$key] ?? null);

        return new BuckarooTokenController(
            $httpClient,
            $this->createMock(LoggerInterface::class),
            $systemConfigService
        );
    }

    private function createSalesChannelContext(): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $salesChannelContext;
    }

    /**
     * @param array<string, string> $query
     */
    private function createRequest(array $query): Request
    {
        $request = new Request($query);
        $request->headers->set('X-Requested-From', 'ShopwareFrontend');

        return $request;
    }
}
