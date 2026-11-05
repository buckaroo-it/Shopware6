<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793750400ConvertExpressButtonSettings;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1793750400ConvertExpressButtonSettingsTest extends TestCase
{
    private const PREFIX = 'BuckarooPayments.config.';

    private const CHANNEL = '98432def39fc4624b33213a56b8c944d';

    private const OTHER_CHANNEL = '0198e2a6f1d97c1f8d5c7f1e2b3a4c5d';

    private const THIRD_CHANNEL = '5f0a9c2e7b1d4e3fa6c8d9b0e1f2a3b4';

    /**
     * @var array<string, array{configuration_key: string, configuration_value: string, sales_channel_id: string|null}>
     */
    private array $store = [];

    /**
     * Apple Pay and Google Pay keep showing the button on the same pages: a sales channel that
     * overrode one former page switch still gets the pages it inherited from "All Sales Channels".
     */
    public function testFormerPageSwitchesBecomePagesAndSwitch(): void
    {
        $this->givenSetting('applepayShowProduct', true);
        $this->givenSetting('applepayShowCart', 0);
        $this->givenSetting('applepayShowCheckout', '1');
        $this->givenSetting('applepayShowCart', true, self::CHANNEL);
        $this->givenSetting('applepayShowCheckout', 'false', self::CHANNEL);
        $this->givenSetting('googlepayShowProduct', false);
        $this->givenSetting('googlepayShowCart', 0);

        $settings = $this->migrate();

        $this->assertTrue($settings['applepayExpressEnabled|']);
        $this->assertSame(['product', 'checkout'], $settings['applepayExpresslocation|']);
        $this->assertSame(['product', 'cart'], $settings['applepayExpresslocation|' . self::CHANNEL]);
        $this->assertArrayNotHasKey(
            'applepayExpressEnabled|' . self::CHANNEL,
            $settings,
            'The switch is on for All Sales Channels as well, so the sales channel keeps inheriting it'
        );
        $this->assertFalse($settings['googlepayExpressEnabled|']);
        $this->assertArrayNotHasKey('googlepayExpresslocation|', $settings);
        $this->assertTrue($settings['applepayShowProduct|'], 'update() leaves the old values in place');
    }

    /**
     * Shopware ignored a sales channel's 0 or "0" and used "All Sales Channels", so such a
     * sales channel keeps showing the button there and keeps inheriting after the update
     */
    public function testEmptySalesChannelPageSwitchesKeepInheriting(): void
    {
        $this->givenSetting('applepayShowProduct', 1);
        $this->givenSetting('applepayShowProduct', 0, self::CHANNEL);
        $this->givenSetting('applepayShowCart', '0', self::CHANNEL);

        $settings = $this->migrate();

        $this->assertTrue($settings['applepayExpressEnabled|']);
        $this->assertSame(['product'], $settings['applepayExpresslocation|']);
        $this->assertArrayNotHasKey('applepayExpressEnabled|' . self::CHANNEL, $settings);
        $this->assertArrayNotHasKey('applepayExpresslocation|' . self::CHANNEL, $settings);
    }

    /**
     * A sales channel that switched a page off where "All Sales Channels" had it on only gets
     * the values that differ: its own switch off, no copy of the pages
     */
    public function testSalesChannelOnlyGetsWhatDiffers(): void
    {
        $this->givenSetting('googlepayShowCart', true);
        $this->givenSetting('googlepayShowCart', false, self::CHANNEL);

        $settings = $this->migrate();

        $this->assertTrue($settings['googlepayExpressEnabled|']);
        $this->assertFalse($settings['googlepayExpressEnabled|' . self::CHANNEL]);
        $this->assertArrayNotHasKey('googlepayExpresslocation|' . self::CHANNEL, $settings);
    }

    /**
     * PayPal Express is on for All Sales Channels: sales channels with their own pages inherit
     * the switch, a sales channel with only "None" gets it off
     */
    public function testNewSwitchIsOnlyWrittenWhereItDiffers(): void
    {
        $this->givenSetting('paypalExpresslocation', ['product']);
        $this->givenSetting('paypalExpresslocation', ['cart'], self::CHANNEL);
        $this->givenSetting('paypalExpresslocation', ['none'], self::OTHER_CHANNEL);

        $settings = $this->migrate();

        $this->assertTrue($settings['paypalExpressEnabled|']);
        $this->assertArrayNotHasKey('paypalExpressEnabled|' . self::CHANNEL, $settings);
        $this->assertSame(['cart'], $settings['paypalExpresslocation|' . self::CHANNEL]);
        $this->assertFalse($settings['paypalExpressEnabled|' . self::OTHER_CHANNEL]);
        $this->assertArrayNotHasKey('paypalExpresslocation|' . self::OTHER_CHANNEL, $settings);
    }

    /**
     * "None" only: switch off and the list removed. Pages: "None" removed, a new switch on.
     * An empty list keeps inheriting, and the existing iDEAL switch only changes for "None".
     */
    public function testNoneBecomesASwitch(): void
    {
        $this->givenSetting('paypalExpresslocation', ['none']);
        $this->givenSetting('paypalExpresslocation', ['none', 'cart'], self::CHANNEL);
        $this->givenSetting('paypalExpresslocation', [], self::OTHER_CHANNEL);
        $this->givenSetting('idealFastCheckoutVisibility', ['product']);
        $this->givenSetting('idealFastCheckoutEnabled', true);
        $this->givenSetting('idealFastCheckoutVisibility', ['none'], self::CHANNEL);

        $settings = $this->migrate();

        $this->assertFalse($settings['paypalExpressEnabled|']);
        $this->assertArrayNotHasKey('paypalExpresslocation|', $settings);
        $this->assertTrue($settings['paypalExpressEnabled|' . self::CHANNEL]);
        $this->assertSame(['cart'], $settings['paypalExpresslocation|' . self::CHANNEL]);
        $this->assertArrayNotHasKey('paypalExpressEnabled|' . self::OTHER_CHANNEL, $settings);
        $this->assertSame([], $settings['paypalExpresslocation|' . self::OTHER_CHANNEL]);

        $this->assertTrue($settings['idealFastCheckoutEnabled|']);
        $this->assertSame(['product'], $settings['idealFastCheckoutVisibility|']);
        $this->assertFalse($settings['idealFastCheckoutEnabled|' . self::CHANNEL]);
        $this->assertArrayNotHasKey('idealFastCheckoutVisibility|' . self::CHANNEL, $settings);
    }

    /**
     * Turning the global iDEAL switch off for "None" must not hide the button in sales channels
     * that have their own pages and inherited the switch
     */
    public function testExistingSwitchStaysOnForSalesChannelsWithOwnPages(): void
    {
        $this->givenSetting('idealFastCheckoutEnabled', true);
        $this->givenSetting('idealFastCheckoutVisibility', ['none']);
        $this->givenSetting('idealFastCheckoutVisibility', ['product'], self::CHANNEL);
        $this->givenSetting('idealFastCheckoutVisibility', ['cart'], self::OTHER_CHANNEL);
        $this->givenSetting('idealFastCheckoutEnabled', false, self::OTHER_CHANNEL);
        $this->givenSetting('idealFastCheckoutVisibility', ['cart'], self::THIRD_CHANNEL);
        $this->givenSetting('idealFastCheckoutEnabled', 0, self::THIRD_CHANNEL);

        $settings = $this->migrate();

        $this->assertFalse($settings['idealFastCheckoutEnabled|']);
        $this->assertTrue($settings['idealFastCheckoutEnabled|' . self::CHANNEL]);
        $this->assertSame(['product'], $settings['idealFastCheckoutVisibility|' . self::CHANNEL]);
        $this->assertFalse($settings['idealFastCheckoutEnabled|' . self::OTHER_CHANNEL]);
        $this->assertTrue(
            $settings['idealFastCheckoutEnabled|' . self::THIRD_CHANNEL],
            'A sales channel value of 0 was ignored, so the channel inherited the switch that was on'
        );
    }

    public function testChangesNothingWhenNothingWasSaved(): void
    {
        $this->assertSame([], $this->migrate());
    }

    public function testDestructiveStepRemovesOnlyTheFormerPageSwitches(): void
    {
        $this->givenSetting('applepayShowProduct', true);
        $this->givenSetting('googlepayShowCheckout', '1', self::CHANNEL);
        $this->givenSetting('applepayExpresslocation', ['product']);

        $settings = $this->migrate(destructive: true);
        $names = array_keys($settings);
        sort($names);

        $this->assertSame([
            'applepayExpressEnabled|',
            'applepayExpresslocation|',
            'googlepayExpressEnabled|' . self::CHANNEL,
            'googlepayExpresslocation|' . self::CHANNEL,
        ], $names);
    }

    private function givenSetting(string $name, mixed $value, ?string $salesChannelId = null): void
    {
        $this->store[Uuid::randomHex()] = [
            'configuration_key' => self::PREFIX . $name,
            'configuration_value' => (string) json_encode(['_value' => $value]),
            'sales_channel_id' => $salesChannelId === null ? null : Uuid::fromHexToBytes($salesChannelId),
        ];
    }

    /**
     * Runs the migration against an in-memory system_config table
     *
     * @return array<string, mixed> "name|sales channel id" => value
     */
    private function migrate(bool $destructive = false): array
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params): array {
                $rows = [];
                foreach ($this->store as $id => $row) {
                    // Former page switches: selected by key list, sales channel id as hex
                    if (str_contains($sql, 'IN (?, ?, ?)')) {
                        if (in_array($row['configuration_key'], $params, true)) {
                            $rows[] = [
                                'configuration_key' => $row['configuration_key'],
                                'configuration_value' => $row['configuration_value'],
                                'sales_channel_id' => $row['sales_channel_id'] === null
                                    ? null
                                    : Uuid::fromBytesToHex($row['sales_channel_id']),
                            ];
                        }
                    } elseif ($row['configuration_key'] === $params['key']) {
                        $rows[] = ['id' => $id] + $row;
                    }
                }

                return $rows;
            }
        );

        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params): int {
                foreach ($this->store as $id => $row) {
                    if (array_is_list($params)) {
                        // DELETE ... WHERE configuration_key IN (?, ...)
                        $matches = in_array($row['configuration_key'], $params, true);
                    } else {
                        $matches = isset($params['id'])
                            ? $id === $params['id']
                            : $row['configuration_key'] === $params['key'] && $row['sales_channel_id'] === $params['salesChannelId'];
                    }
                    if ($matches) {
                        unset($this->store[$id]);
                    }
                }

                return 1;
            }
        );

        $connection->method('insert')->willReturnCallback(
            function (string $table, array $data): int {
                $this->store[Uuid::randomHex()] = [
                    'configuration_key' => $data['configuration_key'],
                    'configuration_value' => $data['configuration_value'],
                    'sales_channel_id' => $data['sales_channel_id'],
                ];

                return 1;
            }
        );

        $migration = new Migration1793750400ConvertExpressButtonSettings();
        $migration->update($connection);
        if ($destructive) {
            $migration->updateDestructive($connection);
        }

        $settings = [];
        foreach ($this->store as $row) {
            $name = substr($row['configuration_key'], strlen(self::PREFIX));
            $channel = $row['sales_channel_id'] === null ? '' : Uuid::fromBytesToHex($row['sales_channel_id']);
            $settings[$name . '|' . $channel] = json_decode($row['configuration_value'], true)['_value'];
        }

        return $settings;
    }
}
