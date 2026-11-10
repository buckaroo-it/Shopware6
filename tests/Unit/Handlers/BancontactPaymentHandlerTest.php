<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Handlers;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Handlers\BancontactPaymentHandler;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Bancontact Hosted Fields replace the phased out Client Side Encryption flow.
 *
 * A Hosted Fields session token is paid with the PayWithToken action of the
 * bancontactmrcash service. The Buckaroo SDK only implements that action on its
 * credit card method, so the client is switched to it for token payments only.
 * Without a usable token, or with Hosted Fields disabled for the sales channel,
 * the standard Bancontact redirect flow stays untouched.
 */
class BancontactPaymentHandlerTest extends TestCase
{
    private const SALES_CHANNEL_ID = '98432def39fc4624b33213a56b8c944d';

    /**
     * @dataProvider enabledSettingProvider
     */
    public function testHostedFieldsTokenIsPaidWithTheBancontactService(mixed $enabled): void
    {
        $handler = $this->createHandler($enabled);
        $dataBag = new RequestDataBag(['buckaroo-token' => 'session-token']);

        $this->assertSame(
            ['name' => 'bancontactmrcash', 'sessionId' => 'session-token'],
            $handler->getMethodPayload(new OrderEntity(), $dataBag, $this->createContext(), 'bancontactmrcash')
        );
        $this->assertSame('payWithToken', $handler->getMethodAction($dataBag, $this->createContext()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function enabledSettingProvider(): array
    {
        return [
            'bool' => [true],
            'int' => [1],
            'string' => ['1'],
        ];
    }

    /**
     * @dataProvider redirectDataBagProvider
     */
    public function testRedirectFlowIsKeptWithoutAUsableToken(RequestDataBag $dataBag): void
    {
        $this->assertRedirectFlow($this->createHandler(true), $dataBag, $this->createContext());
    }

    /**
     * The hidden token field is always posted with the confirm order form, so an
     * order placed without the Hosted Fields submit arrives with an empty token.
     *
     * @return array<string, array{RequestDataBag}>
     */
    public static function redirectDataBagProvider(): array
    {
        return [
            'no token' => [new RequestDataBag()],
            'empty token' => [new RequestDataBag(['buckaroo-token' => ''])],
            'blank token' => [new RequestDataBag(['buckaroo-token' => '  '])],
            'encrypted card data' => [new RequestDataBag(['encryptedCardData' => 'encrypted'])],
        ];
    }

    /**
     * @dataProvider disabledSettingProvider
     */
    public function testTokenIsIgnoredWhenHostedFieldsAreDisabled(mixed $enabled): void
    {
        $this->assertRedirectFlow(
            $this->createHandler($enabled),
            new RequestDataBag(['buckaroo-token' => 'session-token']),
            $this->createContext()
        );
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function disabledSettingProvider(): array
    {
        return [
            'not set' => [null],
            'false' => [false],
            'zero' => ['0'],
        ];
    }

    public function testTokenIsIgnoredWithoutSalesChannelContext(): void
    {
        $handler = $this->createHandler(true);

        $this->assertSame(
            'pay',
            $handler->getMethodAction(new RequestDataBag(['buckaroo-token' => 'session-token']))
        );
    }

    /**
     * @dataProvider clientHookProvider
     */
    public function testTokenPaymentIsSentThroughTheSdkCreditCardMethod(string $hook): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getAction')->willReturn('payWithToken');
        $client->expects($this->once())->method('setPaymentCode')->with('creditcard');

        $this->callHook($this->createHandler(true), $hook, $client);
    }

    /**
     * @dataProvider clientHookProvider
     */
    public function testRedirectPaymentKeepsTheBancontactMethod(string $hook): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getAction')->willReturn('pay');
        $client->expects($this->never())->method('setPaymentCode');

        $this->callHook($this->createHandler(true), $hook, $client);
    }

    /**
     * configureClient() runs on the Shopware 6.7 pay flow, configureLegacyClient()
     * on the 6.5/6.6 one.
     *
     * @return array<string, array{string}>
     */
    public static function clientHookProvider(): array
    {
        return [
            'Shopware 6.7' => ['configureClient'],
            'Shopware 6.5/6.6' => ['configureLegacyClient'],
        ];
    }

    /**
     * On Shopware 6.5/6.6 the request is sent by the delegated legacy handler,
     * which reaches this handler's hook through runConfigureLegacyClient().
     */
    public function testLegacyHandlerReachesTheConfigureHook(): void
    {
        $handler = $this->createHandler(true);

        if (!method_exists($handler, 'runConfigureLegacyClient')) {
            $this->markTestSkipped('The delegated legacy pay flow only exists on Shopware < 6.7');
        }

        $client = $this->createMock(Client::class);
        $client->method('getAction')->willReturn('payWithToken');
        $client->expects($this->once())->method('setPaymentCode')->with('creditcard');

        $handler->runConfigureLegacyClient($client, 'bancontactmrcash', $this->createContext());
    }

    private function assertRedirectFlow(
        BancontactPaymentHandler $handler,
        RequestDataBag $dataBag,
        SalesChannelContext $context
    ): void {
        $this->assertSame(
            [],
            $handler->getMethodPayload(new OrderEntity(), $dataBag, $context, 'bancontactmrcash')
        );
        $this->assertSame('pay', $handler->getMethodAction($dataBag, $context));
    }

    private function createHandler(mixed $hostedFieldsEnabled): BancontactPaymentHandler
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')
            ->willReturnCallback(
                fn (string $key, ?string $salesChannelId = null) =>
                    $key === 'bancontactmrcashHostedFields' && $salesChannelId === self::SALES_CHANNEL_ID
                        ? $hostedFieldsEnabled
                        : null
            );

        $asyncPaymentService = $this->createMock(AsyncPaymentService::class);
        $asyncPaymentService->settingsService = $settingsService;

        return new BancontactPaymentHandler(
            $asyncPaymentService,
            $this->createMock(FormatRequestParamService::class)
        );
    }

    private function createContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL_ID);

        return $context;
    }

    private function callHook(BancontactPaymentHandler $handler, string $hook, Client $client): void
    {
        $method = new \ReflectionMethod($handler, $hook);
        $method->setAccessible(true);
        $method->invoke($handler, $client, 'bancontactmrcash', $this->createContext());
    }
}
