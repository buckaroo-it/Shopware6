<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\TestCredentialsService;
use Buckaroo\Shopware6\Service\UrlService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class TestCredentialsServiceTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191c6b0e7d4705a8d6f2a6b3c4d5e6f';

    private TestCredentialsService $service;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var ClientService&MockObject */
    private ClientService $clientService;

    /** @var Client&MockObject */
    private Client $client;

    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->clientService = $this->createMock(ClientService::class);
        $this->client = $this->createMock(Client::class);
        $this->client->method('setPayload')->willReturnSelf();

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->service = new TestCredentialsService(
            $this->settingsService,
            $this->createMock(UrlService::class),
            $this->clientService,
            $translator
        );
    }

    private function createRequest(?string $salesChannelId = self::SALES_CHANNEL_ID): Request
    {
        return new Request([], [
            'websiteKeyId' => 'new-website-key',
            'secretKeyId' => 'new-secret-key',
            'saleChannelId' => $salesChannelId,
        ], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function mockResponse(?int $httpStatusCode, ?int $statusCode): void
    {
        $response = $this->createMock(ClientResponseInterface::class);
        $response->method('getHttpStatusCode')->willReturn($httpStatusCode);
        $response->method('getStatusCode')->willReturn($statusCode);

        $this->client->method('execute')->willReturn($response);
    }

    public function testSubmittedCredentialsAreTestedWithoutBeingStored(): void
    {
        $this->mockResponse(200, 491);

        $this->settingsService->expects($this->never())->method('setSetting');
        $this->clientService
            ->expects($this->once())
            ->method('getWithCredentials')
            ->with('ideal', 'new-website-key', 'new-secret-key', self::SALES_CHANNEL_ID)
            ->willReturn($this->client);
        $this->clientService->expects($this->never())->method('get');

        $result = $this->service->execute($this->createRequest());

        $this->assertSame('success', $result['status']);
        $this->assertSame('buckaroo-payment.test_api.connection_ready', $result['message']);
    }

    public function testGlobalSalesChannelIsAccepted(): void
    {
        $this->mockResponse(200, 491);

        $this->clientService
            ->expects($this->once())
            ->method('getWithCredentials')
            ->with('ideal', 'new-website-key', 'new-secret-key', null)
            ->willReturn($this->client);

        $result = $this->service->execute($this->createRequest(null));

        $this->assertSame('success', $result['status']);
    }

    public function testErrorHttpResponseReportsFailure(): void
    {
        $this->mockResponse(401, null);
        $this->clientService->method('getWithCredentials')->willReturn($this->client);

        $result = $this->service->execute($this->createRequest());

        $this->assertSame('error', $result['status']);
        $this->assertSame('buckaroo-payment.test_api.connection_failed', $result['message']);
    }

    public function testResponseWithoutTransactionStatusReportsFailure(): void
    {
        $this->mockResponse(200, null);
        $this->clientService->method('getWithCredentials')->willReturn($this->client);

        $result = $this->service->execute($this->createRequest());

        $this->assertSame('error', $result['status']);
    }

    public function testClientExceptionReportsFailure(): void
    {
        $this->client->method('execute')->willThrowException(new \Exception('Transfer failed'));
        $this->clientService->method('getWithCredentials')->willReturn($this->client);

        $result = $this->service->execute($this->createRequest());

        $this->assertSame('error', $result['status']);
    }

    public function testInvalidSalesChannelIdIsRejected(): void
    {
        $this->settingsService->expects($this->never())->method('setSetting');
        $this->clientService->expects($this->never())->method('getWithCredentials');

        $result = $this->service->execute($this->createRequest('not-a-uuid'));

        $this->assertSame('error', $result['status']);
    }
}
