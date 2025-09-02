<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1756680000RenameIdealIn3ToIn3 extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1756680000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "UPDATE `system_config`
             SET `configuration_value` = JSON_SET(`configuration_value`, '$._value', 'In3')
             WHERE `configuration_key` = :configKey
               AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(`configuration_value`, '$._value'))) LIKE '%ideal%'",
            [
                'configKey' => 'BuckarooPayments.config.capayableLabel',
            ]
        );
        
        $connection->executeStatement(
            "UPDATE `payment_method_translation` pmt
             INNER JOIN `payment_method` pm ON pm.id = pmt.payment_method_id
             INNER JOIN `language` lang ON lang.id = pmt.language_id
             INNER JOIN `locale` loc ON loc.id = lang.locale_id
             SET pmt.name = 'In3', pmt.description = CASE
                 WHEN loc.code = 'de-DE' THEN 'Bezahlen mit In3'
                 WHEN loc.code = 'en-GB' THEN 'Pay with In3'
                 ELSE pmt.description
             END
             WHERE pm.handler_identifier = :handler",
            [
                'handler' => 'Buckaroo\\Shopware6\\Handlers\\In3PaymentHandler',
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}


