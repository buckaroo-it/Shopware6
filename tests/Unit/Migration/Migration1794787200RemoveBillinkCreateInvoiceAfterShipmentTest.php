<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1794787200RemoveBillinkCreateInvoiceAfterShipment;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1794787200RemoveBillinkCreateInvoiceAfterShipmentTest extends TestCase
{
    /**
     * Deletes the stored values of the setting, for "All Sales Channels" and every sales
     * channel, and nothing else
     */
    public function testDeletesOnlyTheStoredSetting(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                'DELETE FROM `system_config` WHERE `configuration_key` = :key',
                ['key' => 'BuckarooPayments.config.BillinkCreateInvoiceAfterShipment']
            )
            ->willReturn(2);

        (new Migration1794787200RemoveBillinkCreateInvoiceAfterShipment())->update($connection);
    }

    public function testTheSettingIsGoneFromTheConfig(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../../src/Resources/config/config.xml');

        $this->assertStringNotContainsString('BillinkCreateInvoiceAfterShipment', $config);
    }
}
