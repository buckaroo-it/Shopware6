<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Storefront\Controller\BuckarooTokenController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
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

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        $request = new Request();
        $request->headers->set('X-Requested-From', 'ShopwareFrontend');

        $response = (new BuckarooTokenController($httpClient, $this->createMock(LoggerInterface::class), $systemConfigService))
            ->getOAuthToken($request, $salesChannelContext);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertSame([
            'BuckarooPayments.config.clientId' => self::SALES_CHANNEL_ID,
            'BuckarooPayments.config.clientSecret' => self::SALES_CHANNEL_ID,
            'BuckarooPayments.config.allowedcreditcards' => self::SALES_CHANNEL_ID,
        ], $requested);
    }
}
