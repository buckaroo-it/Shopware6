<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Updates payment method name and description to co-branded "iDEAL | Wero"
 * and the idealLabel system config (mandatory from 29 January 2026).
 */
class Migration1769900000IdealWeroRebrand extends MigrationStep
{
    private const IDEAL_HANDLER = 'Buckaroo\\Shopware6\\Handlers\\IdealPaymentHandler';
    private const NEW_NAME = 'iDEAL | Wero';
    private const NEW_DESCRIPTION_EN = 'Pay with iDEAL | Wero';
    private const NEW_DESCRIPTION_DE = 'Bezahlen mit iDEAL | Wero';

    public function getCreationTimestamp(): int
    {
        return 1769900000;
    }

    public function update(Connection $connection): void
    {
        // 1. Update payment_method_translation: name and description for iDEAL
        $connection->executeStatement(
            "UPDATE payment_method_translation pmt
             INNER JOIN payment_method pm ON pm.id = pmt.payment_method_id
             INNER JOIN language lang ON lang.id = pmt.language_id
             INNER JOIN locale loc ON loc.id = lang.locale_id
             SET
                 pmt.name = :name,
                 pmt.description = CASE
                     WHEN loc.code = 'de-DE' THEN :descriptionDe
                     WHEN loc.code = 'en-GB' THEN :descriptionEn
                     WHEN loc.code = 'nl-NL' THEN :descriptionEn
                     WHEN loc.code = 'fr-FR' THEN :descriptionEn
                     ELSE pmt.description
                 END
             WHERE pm.handler_identifier = :handler",
            [
                'handler'       => self::IDEAL_HANDLER,
                'name'          => self::NEW_NAME,
                'descriptionEn' => self::NEW_DESCRIPTION_EN,
                'descriptionDe' => self::NEW_DESCRIPTION_DE,
            ]
        );

        // 2. Update system_config idealLabel to "iDEAL | Wero" (used for storefront payment_labels)
        // Skip if system_config table does not exist (e.g. table prefix, or core not fully migrated)
        $schemaManager = method_exists($connection, 'createSchemaManager')
            ? $connection->createSchemaManager()
            : $connection->getSchemaManager();
        $tableExists = $schemaManager->tablesExist(['system_config']);

        if ($tableExists) {
            $connection->executeStatement(
                "UPDATE system_config
                 SET configuration_value = JSON_SET(COALESCE(configuration_value, '{}'), '$._value', :newValue)
                 WHERE configuration_key = 'BuckarooPayments.config.idealLabel'
                   AND (JSON_UNQUOTE(JSON_EXTRACT(configuration_value, '$._value')) = 'iDEAL'
                       OR JSON_EXTRACT(configuration_value, '$._value') IS NULL)",
                [
                    'newValue' => self::NEW_NAME,
                ]
            );
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}
