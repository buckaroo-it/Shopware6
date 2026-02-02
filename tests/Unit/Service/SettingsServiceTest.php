<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Buckaroo\Shopware6\Service\SettingsService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;

class SettingsServiceTest extends TestCase
{
    private SettingsService $settingsService;

    /** @var SystemConfigService&MockObject */
    private SystemConfigService $systemConfigService;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->settingsService = new SettingsService($this->systemConfigService);
    }

    /**
     * Test: it retrieves setting with correct prefix and sales channel
     */
    public function testGetSettingWithSalesChannel(): void
    {
        // Arrange
        $salesChannelId = 'test-sales-channel-id';
        $settingName = 'secretKey';
        $expectedValue = 'test-secret-key';

        $this->systemConfigService
            ->expects($this->once())
            ->method('get')
            ->with('BuckarooPayments.config.' . $settingName, $salesChannelId)
            ->willReturn($expectedValue);

        // Act
        $result = $this->settingsService->getSetting($settingName, $salesChannelId);

        // Assert
        $this->assertSame($expectedValue, $result);
    }

    /**
     * Test: it retrieves setting without sales channel (null)
     */
    public function testGetSettingWithoutSalesChannel(): void
    {
        // Arrange
        $settingName = 'websiteKey';
        $expectedValue = 'test-website-key';

        $this->systemConfigService
            ->expects($this->once())
            ->method('get')
            ->with('BuckarooPayments.config.' . $settingName, null)
            ->willReturn($expectedValue);

        // Act
        $result = $this->settingsService->getSetting($settingName, null);

        // Assert
        $this->assertSame($expectedValue, $result);
    }

    /**
     * Test: it returns empty string when setting is array
     */
    public function testGetSettingAsStringReturnsEmptyStringWhenValueIsArray(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $this->systemConfigService
            ->method('get')
            ->willReturn(['invalid' => 'array']);

        // Act
        $result = $this->settingsService->getSettingAsString($settingName);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * Test: it returns empty string when setting is null
     */
    public function testGetSettingAsStringReturnsEmptyStringWhenValueIsNull(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $this->systemConfigService
            ->method('get')
            ->willReturn(null);

        // Act
        $result = $this->settingsService->getSettingAsString($settingName);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * Test: it converts scalar values to string
     */
    public function testGetSettingAsStringConvertsScalarToString(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $this->systemConfigService
            ->method('get')
            ->willReturn(123);

        // Act
        $result = $this->settingsService->getSettingAsString($settingName);

        // Assert
        $this->assertSame('123', $result);
    }

    /**
     * Test: it sets setting with correct prefix and sales channel
     */
    public function testSetSettingWithScalarValue(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $value = 'test-value';
        $salesChannelId = 'test-channel';

        $this->systemConfigService
            ->expects($this->once())
            ->method('set')
            ->with('BuckarooPayments.config.' . $settingName, $value, $salesChannelId);

        // Act
        $this->settingsService->setSetting($settingName, $value, $salesChannelId);
    }

    /**
     * Test: it sets setting with array value
     */
    public function testSetSettingWithArrayValue(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $value = ['key' => 'value'];
        $salesChannelId = 'test-channel';

        $this->systemConfigService
            ->expects($this->once())
            ->method('set')
            ->with('BuckarooPayments.config.' . $settingName, $value, $salesChannelId);

        // Act
        $this->settingsService->setSetting($settingName, $value, $salesChannelId);
    }

    /**
     * Test: it does not set non-scalar and non-array values (objects)
     */
    public function testSetSettingDoesNotSetObjectValues(): void
    {
        // Arrange
        $settingName = 'testSetting';
        $value = new \stdClass();

        $this->systemConfigService
            ->expects($this->never())
            ->method('set');

        // Act
        $this->settingsService->setSetting($settingName, $value);
    }

    /**
     * Test: it retrieves shop name from core config
     */
    public function testGetShopNameReturnsValidShopName(): void
    {
        // Arrange
        $salesChannelId = 'test-channel';
        $expectedShopName = 'My Test Shop';

        $this->systemConfigService
            ->expects($this->once())
            ->method('get')
            ->with('core.basicInformation.shopName', $salesChannelId)
            ->willReturn($expectedShopName);

        // Act
        $result = $this->settingsService->getShopName($salesChannelId);

        // Assert
        $this->assertSame($expectedShopName, $result);
    }

    /**
     * Test: it returns empty string when shop name is not a string
     */
    public function testGetShopNameReturnsEmptyStringWhenNotString(): void
    {
        // Arrange
        $this->systemConfigService
            ->method('get')
            ->willReturn(null);

        // Act
        $result = $this->settingsService->getShopName();

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * Test: it returns shop name when label is empty
     */
    public function testGetParsedLabelReturnsShopNameWhenLabelIsEmpty(): void
    {
        // Arrange
        $order = new OrderEntity();
        $salesChannelId = 'test-channel';
        $shopName = 'Test Shop';

        $this->systemConfigService
            ->method('get')
            ->willReturnMap([
                ['BuckarooPayments.config.refundLabel', $salesChannelId, ''],
                ['core.basicInformation.shopName', $salesChannelId, $shopName],
            ]);

        // Act
        $result = $this->settingsService->getParsedLabel($order, $salesChannelId, 'refundLabel');

        // Assert
        $this->assertSame($shopName, $result);
    }

    /**
     * Test: it replaces order_number placeholder in label
     */
    public function testGetParsedLabelReplacesOrderNumber(): void
    {
        // Arrange
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-12345');
        $salesChannelId = 'test-channel';
        $label = 'Payment for {order_number}';
        $shopName = 'Test Shop';

        $this->systemConfigService
            ->method('get')
            ->willReturnMap([
                ['BuckarooPayments.config.paymentLabel', $salesChannelId, $label],
                ['core.basicInformation.shopName', $salesChannelId, $shopName],
            ]);

        // Act
        $result = $this->settingsService->getParsedLabel($order, $salesChannelId, 'paymentLabel');

        // Assert
        $this->assertSame('Payment for ORDER-12345', $result);
    }

    /**
     * Test: it replaces shop_name placeholder in label
     */
    public function testGetParsedLabelReplacesShopName(): void
    {
        // Arrange
        $order = new OrderEntity();
        $salesChannelId = 'test-channel';
        $label = 'Payment at {shop_name}';
        $shopName = 'Amazing Store';

        $this->systemConfigService
            ->method('get')
            ->willReturnMap([
                ['BuckarooPayments.config.paymentLabel', $salesChannelId, $label],
                ['core.basicInformation.shopName', $salesChannelId, $shopName],
            ]);

        // Act
        $result = $this->settingsService->getParsedLabel($order, $salesChannelId, 'paymentLabel');

        // Assert
        $this->assertSame('Payment at Amazing Store', $result);
    }

    /**
     * Test: it replaces product_name placeholder with first product label
     */
    public function testGetParsedLabelReplacesProductName(): void
    {
        // Arrange
        $order = new OrderEntity();
        $salesChannelId = 'test-channel';
        $label = 'Payment for {product_name}';
        $shopName = 'Test Shop';

        $lineItem = new OrderLineItemEntity();
        $lineItem->setId('line-item-id'); // Set ID to initialize unique identifier
        $lineItem->setLabel('Cool Product');
        $lineItems = new OrderLineItemCollection([$lineItem]);
        $order->setLineItems($lineItems);

        $this->systemConfigService
            ->method('get')
            ->willReturnMap([
                ['BuckarooPayments.config.paymentLabel', $salesChannelId, $label],
                ['core.basicInformation.shopName', $salesChannelId, $shopName],
            ]);

        // Act
        $result = $this->settingsService->getParsedLabel($order, $salesChannelId, 'paymentLabel');

        // Assert
        $this->assertSame('Payment for Cool Product', $result);
    }

    /**
     * Test: it truncates label to 244 characters
     */
    public function testGetParsedLabelTruncatesTo244Characters(): void
    {
        // Arrange
        $order = new OrderEntity();
        $salesChannelId = 'test-channel';
        $longLabel = str_repeat('a', 300);
        $shopName = 'Test Shop';

        $this->systemConfigService
            ->method('get')
            ->willReturnMap([
                ['BuckarooPayments.config.paymentLabel', $salesChannelId, $longLabel],
                ['core.basicInformation.shopName', $salesChannelId, $shopName],
            ]);

        // Act
        $result = $this->settingsService->getParsedLabel($order, $salesChannelId, 'paymentLabel');

        // Assert
        $this->assertSame(244, mb_strlen($result));
    }

    /**
     * Test: it returns environment setting
     */
    public function testGetEnvironmentReturnsMethodEnvironment(): void
    {
        // Arrange
        $method = 'ideal';
        $salesChannelId = 'test-channel';
        $environment = 'test';

        $this->systemConfigService
            ->method('get')
            ->with('BuckarooPayments.config.idealEnvironment', $salesChannelId)
            ->willReturn($environment);

        // Act
        $result = $this->settingsService->getEnvironment($method, $salesChannelId);

        // Assert
        $this->assertSame('test', $result);
    }

    /**
     * Test: it returns fixed fee as float
     */
    public function testGetBuckarooFeeReturnsFixedFee(): void
    {
        // Arrange
        $buckarooKey = 'ideal';
        $salesChannelId = 'test-channel';

        $this->systemConfigService
            ->method('get')
            ->with('BuckarooPayments.config.idealFee', $salesChannelId)
            ->willReturn('2.50');

        // Act
        $result = $this->settingsService->getBuckarooFee($buckarooKey, $salesChannelId);

        // Assert
        $this->assertSame(2.50, $result);
    }

    /**
     * Test: it returns 0 when fee is null
     */
    public function testGetBuckarooFeeReturnsZeroWhenNull(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(null);

        // Act
        $result = $this->settingsService->getBuckarooFee($buckarooKey);

        // Assert
        $this->assertSame(0.0, $result);
    }

    /**
     * Test: it handles comma as decimal separator
     */
    public function testGetBuckarooFeeHandlesCommaAsDecimalSeparator(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn('3,75');

        // Act
        $result = $this->settingsService->getBuckarooFee($buckarooKey);

        // Assert
        $this->assertSame(3.75, $result);
    }

    /**
     * Test: it rounds fee to 2 decimals
     */
    public function testGetBuckarooFeeRoundsToTwoDecimals(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn('2.5555');

        // Act
        $result = $this->settingsService->getBuckarooFee($buckarooKey);

        // Assert
        $this->assertSame(2.56, $result);
    }

    /**
     * Test: it returns raw fee value with percentage symbol
     */
    public function testGetBuckarooFeeRawReturnsValueWithPercentage(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn('  5%  ');

        // Act
        $result = $this->settingsService->getBuckarooFeeRaw($buckarooKey);

        // Assert
        $this->assertSame('5%', $result);
    }

    /**
     * Test: it returns empty string when raw fee is null
     */
    public function testGetBuckarooFeeRawReturnsEmptyStringWhenNull(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(null);

        // Act
        $result = $this->settingsService->getBuckarooFeeRaw($buckarooKey);

        // Assert
        $this->assertSame('', $result);
    }

    /**
     * Test: it detects percentage fee
     */
    public function testIsBuckarooFeePercentageReturnsTrueForPercentageFee(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn('5%');

        // Act
        $result = $this->settingsService->isBuckarooFeePercentage($buckarooKey);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns false for fixed fee
     */
    public function testIsBuckarooFeePercentageReturnsFalseForFixedFee(): void
    {
        // Arrange
        $buckarooKey = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn('2.50');

        // Act
        $result = $this->settingsService->isBuckarooFeePercentage($buckarooKey);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it calculates percentage fee correctly
     */
    public function testCalculateBuckarooFeeCalculatesPercentageFee(): void
    {
        // Arrange
        $buckarooKey = 'ideal';
        $orderTotal = 100.00;

        $this->systemConfigService
            ->method('get')
            ->willReturn('5%');

        // Act
        $result = $this->settingsService->calculateBuckarooFee($buckarooKey, $orderTotal);

        // Assert
        $this->assertSame(5.00, $result);
    }

    /**
     * Test: it calculates percentage fee with decimal percentage
     */
    public function testCalculateBuckarooFeeCalculatesDecimalPercentageFee(): void
    {
        // Arrange
        $buckarooKey = 'ideal';
        $orderTotal = 100.00;

        $this->systemConfigService
            ->method('get')
            ->willReturn('2.5%');

        // Act
        $result = $this->settingsService->calculateBuckarooFee($buckarooKey, $orderTotal);

        // Assert
        $this->assertSame(2.50, $result);
    }

    /**
     * Test: it returns fixed fee when not percentage
     */
    public function testCalculateBuckarooFeeReturnsFixedFeeWhenNotPercentage(): void
    {
        // Arrange
        $buckarooKey = 'ideal';
        $orderTotal = 100.00;

        $this->systemConfigService
            ->method('get')
            ->willReturn('3.50');

        // Act
        $result = $this->settingsService->calculateBuckarooFee($buckarooKey, $orderTotal);

        // Assert
        $this->assertSame(3.50, $result);
    }

    /**
     * Test: it returns 0 when fee is empty
     */
    public function testCalculateBuckarooFeeReturnsZeroWhenEmpty(): void
    {
        // Arrange
        $buckarooKey = 'ideal';
        $orderTotal = 100.00;

        $this->systemConfigService
            ->method('get')
            ->willReturn('');

        // Act
        $result = $this->settingsService->calculateBuckarooFee($buckarooKey, $orderTotal);

        // Assert
        $this->assertSame(0.0, $result);
    }

    /**
     * Test: it returns true when method is enabled (value is 1)
     */
    public function testGetEnabledReturnsTrueWhenEnabled(): void
    {
        // Arrange
        $method = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(1);

        // Act
        $result = $this->settingsService->getEnabled($method);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns true when method is enabled (value is true)
     */
    public function testGetEnabledReturnsTrueWhenValueIsTrue(): void
    {
        // Arrange
        $method = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(true);

        // Act
        $result = $this->settingsService->getEnabled($method);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * Test: it returns false when method is disabled (value is 0)
     */
    public function testGetEnabledReturnsFalseWhenDisabled(): void
    {
        // Arrange
        $method = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(0);

        // Act
        $result = $this->settingsService->getEnabled($method);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * Test: it returns false when method is disabled (value is null)
     */
    public function testGetEnabledReturnsFalseWhenNull(): void
    {
        // Arrange
        $method = 'ideal';

        $this->systemConfigService
            ->method('get')
            ->willReturn(null);

        // Act
        $result = $this->settingsService->getEnabled($method);

        // Assert
        $this->assertFalse($result);
    }
}
