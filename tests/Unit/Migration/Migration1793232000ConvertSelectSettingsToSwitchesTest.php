<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Migration;

use Buckaroo\Shopware6\Migration\Migration1793232000ConvertSelectSettingsToSwitches;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class Migration1793232000ConvertSelectSettingsToSwitchesTest extends TestCase
{
    /**
     * Existing shops keep their choice: the former select values become booleans,
     * values that are already booleans are left alone.
     */
    public function testConvertsFormerSelectValuesToBooleans(): void
    {
        $written = $this->runMigration([
            'BuckarooPayments.config.transferSendEmail' => [
                ['id' => 'a', 'configuration_value' => json_encode(['_value' => 1])],
                ['id' => 'b', 'configuration_value' => json_encode(['_value' => '0'])],
                ['id' => 'c', 'configuration_value' => json_encode(['_value' => true])],
            ],
            'BuckarooPayments.config.redirectBancontact' => [
                ['id' => 'd', 'configuration_value' => json_encode(['_value' => 'enabledRedirect'])],
                ['id' => 'e', 'configuration_value' => json_encode(['_value' => 'disabledRedirect'])],
            ],
        ]);

        $this->assertSame(['a' => true, 'b' => false, 'd' => true, 'e' => false], $written);
    }

    /**
     * @param array<string, array<int, array<string, string|false>>> $rowsPerKey
     * @return array<string, bool> row id => written value
     */
    private function runMigration(array $rowsPerKey): array
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql, array $params): array => $rowsPerKey[$params['key']] ?? []
        );

        $written = [];
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$written): int {
                $written[$params['id']] = json_decode($params['value'], true)['_value'];
                return 1;
            }
        );

        (new Migration1793232000ConvertSelectSettingsToSwitches())->update($connection);

        return $written;
    }
}
