<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * @internal
 */
class Migration1744014276RemoveIssuerForHostedFields extends MigrationStep
{

    public function getCreationTimestamp(): int
    {
        return 1744014276;
    }

    public function update(Connection $connection): void
    {
        $configKey = 'BuckarooPayments.config.allowedcreditcards';

        $json = $connection->fetchOne('
            SELECT configuration_value
            FROM system_config
            WHERE configuration_key = :key
        ', ['key' => $configKey]);

        if (!is_string($json) || $json === '') {
            return;
        }
        $decoded = json_decode((string) $json, true);

        if (!is_array($decoded)) {
            return;
        }

        $allowedCards = $decoded;

        $removedCards = [
            'cartebleuevisa',
            'cartebancaire',
            'dankort',
            'nexi',
            'postepay',
            'vpay',
        ];

        $filteredCards = array_values(array_diff($allowedCards, $removedCards));

        if ($filteredCards !== $allowedCards) {
            $connection->executeStatement('
                UPDATE system_config
                SET configuration_value = :value
                WHERE configuration_key = :key
            ', [
                'value' => json_encode($filteredCards),
                'key' => $configKey,
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
