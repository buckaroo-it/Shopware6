<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\In3LogoService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\PayByBankService;
use Buckaroo\Shopware6\Service\IdealIssuerService;
use Buckaroo\Shopware6\Service\PayPalExpressCredentialsService;
use Buckaroo\Shopware6\Subscribers\CheckoutConfirmTemplateSubscriber;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The PayPal Express button style reaches the storefront as PayPal's style option
 * ({color, shape, label}), read per sales channel.
 */
class CheckoutConfirmTemplateSubscriberPaypalButtonStyleTest extends TestCase
{
    private const SALES_CHANNEL_ID = '66666666666666666666666666666666';

    private const SETTINGS = [
        'color' => 'paypalExpressButtonStyle',
        'shape' => 'paypalExpressButtonShape',
        'label' => 'paypalExpressButtonType',
    ];

    /**
     * Nothing saved yet (e.g. right after an update): PayPal's default look
     */
    public function testDefaultsToPaypalsOwnLook(): void
    {
        $this->assertSame(
            ['color' => 'gold', 'shape' => 'rect', 'label' => 'paypal'],
            $this->getStyle([])
        );
    }

    public function testUsesTheSettingsOfTheSalesChannel(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->exactly(3))
            ->method('getSetting')
            ->with($this->anything(), self::SALES_CHANNEL_ID)
            ->willReturnMap([
                ['paypalExpressButtonStyle', self::SALES_CHANNEL_ID, 'black'],
                ['paypalExpressButtonShape', self::SALES_CHANNEL_ID, 'pill'],
                ['paypalExpressButtonType', self::SALES_CHANNEL_ID, 'buynow'],
            ]);

        $this->assertSame(
            ['color' => 'black', 'shape' => 'pill', 'label' => 'buynow'],
            $this->invokeGetStyle($settingsService)
        );
    }

    public function testUnknownValuesFallBackToTheDefault(): void
    {
        $this->assertSame(
            ['color' => 'gold', 'shape' => 'rect', 'label' => 'paypal'],
            $this->getStyle([
                'paypalExpressButtonStyle' => 'purple',
                'paypalExpressButtonShape' => ['pill'],
                'paypalExpressButtonType' => '',
            ])
        );
    }

    /**
     * Every option that can be chosen in config.xml is passed on unchanged
     */
    public function testEveryConfigOptionIsPassedOn(): void
    {
        $document = new DOMDocument();
        $document->load(__DIR__ . '/../../../src/Resources/config/config.xml');
        $xpath = new DOMXPath($document);

        foreach (self::SETTINGS as $styleKey => $setting) {
            $ids = $xpath->query(
                '//*[local-name()="input-field"]/*[local-name()="name"][text()="' . $setting . '"]'
                . '/../*[local-name()="options"]/*[local-name()="option"]/*[local-name()="id"]'
            );
            $this->assertNotFalse($ids);
            $this->assertGreaterThan(0, $ids->length, "$setting has options");

            foreach ($ids as $id) {
                $style = $this->getStyle([$setting => $id->textContent]);
                $this->assertSame($id->textContent, $style[$styleKey], "$setting option {$id->textContent}");
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, string>
     */
    private function getStyle(array $settings): array
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSetting')->willReturnCallback(
            static fn (string $key) => $settings[$key] ?? null
        );

        return $this->invokeGetStyle($settingsService);
    }

    /**
     * @return array<string, string>
     */
    private function invokeGetStyle(SettingsService $settingsService): array
    {
        $subscriber = new CheckoutConfirmTemplateSubscriber(
            $this->createMock(SalesChannelRepository::class),
            $settingsService,
            $this->createMock(UrlService::class),
            $this->createMock(TranslatorInterface::class),
            $this->createMock(PayByBankService::class),
            $this->createMock(In3LogoService::class),
            $this->createMock(IdealIssuerService::class),
            $this->createMock(PayPalExpressCredentialsService::class)
        );

        $method = new \ReflectionMethod($subscriber, 'getPaypalButtonStyle');
        $method->setAccessible(true);

        /** @var array<string, string> $style */
        $style = $method->invoke($subscriber, self::SALES_CHANNEL_ID);

        return $style;
    }
}
