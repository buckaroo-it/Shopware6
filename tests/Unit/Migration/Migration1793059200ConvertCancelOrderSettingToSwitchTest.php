<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793059200ConvertCancelOrderSettingToSwitch;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793059200ConvertCancelOrderSettingToSwitchTest extends TestCase
{
    /**
     * Existing shops keep their choice: "enabled"/"disabled" become booleans,
     * values that are already booleans are left alone.
     */
    public function testConvertsFormerSelectValuesToBooleans(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')
            ->with(
                $this->anything(),
                ['key' => Migration1793059200ConvertCancelOrderSettingToSwitch::CONFIG_KEY]
            )
            ->willReturn([
                ['id' => 'a', 'configuration_value' => json_encode(['_value' => 'enabled'])],
                ['id' => 'b', 'configuration_value' => json_encode(['_value' => 'disabled'])],
                ['id' => 'c', 'configuration_value' => json_encode(['_value' => 'Enabled'])],
                ['id' => 'd', 'configuration_value' => json_encode(['_value' => true])],
            ]);

        $written = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$written): int {
                $written[$params['id']] = json_decode($params['value'], true)['_value'];
                return 1;
            }
        );

        (new Migration1793059200ConvertCancelOrderSettingToSwitch())->update($connection);

        $this->assertSame(['a' => true, 'b' => false, 'c' => true], $written);
    }
}
