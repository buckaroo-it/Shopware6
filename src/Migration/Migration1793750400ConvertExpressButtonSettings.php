<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Every express button (PayPal Express, Apple Pay, Google Pay and iDEAL | Wero Fast Checkout)
 * now has an on/off switch and a list of pages without a "None" option. Convert the former
 * settings per sales channel, so every shop keeps showing the buttons on the same pages:
 *
 * - Apple Pay and Google Pay had a switch per page ("Show ... on product page / in the cart /
 *   on checkout page"). They become the page list, and the button switch is on when a page
 *   was on. A sales channel that overrode only some switches gets the pages it inherited
 *   from "All Sales Channels" as well. updateDestructive() removes the former switches.
 * - A page list with only "None" hid the button: the switch is turned off and the list removed.
 *   Otherwise "None" is removed from the list, and a new switch is turned on. Sales channels with
 *   their own pages that inherited an existing switch that was on keep it on.
 * - An empty page list inherits from "All Sales Channels" and is left alone.
 *
 * As in the shop, an empty value of a sales channel (0, "0", "", []) is not its own value. A sales
 * channel only gets its own switch or pages where they differ from "All Sales Channels", so it
 * keeps inheriting everything else.
 *
 * @internal
 */
class Migration1793750400ConvertExpressButtonSettings extends MigrationStep
{
    private const PREFIX = 'BuckarooPayments.config.';

    /**
     * Former switch per page of the Apple Pay and Google Pay express buttons
     */
    public const FORMER_PAGE_SWITCHES = [
        'product'  => 'ShowProduct',
        'cart'     => 'ShowCart',
        'checkout' => 'ShowCheckout',
    ];

    public const METHODS_WITH_FORMER_PAGE_SWITCHES = ['applepay', 'googlepay'];

    /**
     * Page list => [switch, whether the switch is new]
     */
    public const BUTTONS = [
        'paypalExpresslocation'       => ['paypalExpressEnabled', true],
        'applepayExpresslocation'     => ['applepayExpressEnabled', true],
        'googlepayExpresslocation'    => ['googlepayExpressEnabled', true],
        'idealFastCheckoutVisibility' => ['idealFastCheckoutEnabled', false],
    ];

    public function getCreationTimestamp(): int
    {
        return 1793750400;
    }

    public function update(Connection $connection): void
    {
        foreach (self::METHODS_WITH_FORMER_PAGE_SWITCHES as $method) {
            $this->convertFormerPageSwitches($connection, $method);
        }

        foreach (self::BUTTONS as $locationKey => [$enabledKey, $isNewSwitch]) {
            $this->convertNoneToSwitch($connection, $locationKey, $enabledKey, $isNewSwitch);
        }
    }

    /**
     * The former Apple Pay and Google Pay page switches are converted by update() and no longer used
     */
    public function updateDestructive(Connection $connection): void
    {
        $keys = [];
        foreach (self::METHODS_WITH_FORMER_PAGE_SWITCHES as $method) {
            foreach (self::FORMER_PAGE_SWITCHES as $suffix) {
                $keys[] = self::PREFIX . $method . $suffix;
            }
        }

        // Positional placeholders: array parameter types differ between DBAL 3 and 4
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` IN ('
                . implode(', ', array_fill(0, count($keys), '?')) . ')',
            $keys
        );
    }

    private function convertFormerPageSwitches(Connection $connection, string $method): void
    {
        $keys = [];
        foreach (self::FORMER_PAGE_SWITCHES as $page => $suffix) {
            $keys[self::PREFIX . $method . $suffix] = $page;
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT `configuration_key`, `configuration_value`, LOWER(HEX(`sales_channel_id`)) AS `sales_channel_id`
             FROM `system_config` WHERE `configuration_key` IN (?, ?, ?)',
            array_keys($keys)
        );

        // [sales channel id, or '' for "All Sales Channels"][page] => bool
        $values = [];
        foreach ($rows as $row) {
            $key = $row['configuration_key'];
            if (!is_string($key) || !isset($keys[$key])) {
                continue;
            }

            $salesChannelId = is_string($row['sales_channel_id']) ? $row['sales_channel_id'] : '';
            $value = $this->storedValue($row['configuration_value']);

            // Shopware ignored an empty value of a sales channel: that page followed "All Sales Channels"
            if ($salesChannelId !== '' && !$this->overridesInheritedValue($value)) {
                continue;
            }

            $values[$salesChannelId][$keys[$key]] = $this->toBool($value);
        }

        $global = $values[''] ?? [];
        $globalPages = $this->selectedPages($global, []);
        $enabledKey = self::PREFIX . $method . 'ExpressEnabled';
        $locationKey = self::PREFIX . $method . 'Expresslocation';

        if (isset($values[''])) {
            $this->write($connection, $enabledKey, null, $globalPages !== []);
            if ($globalPages === []) {
                $this->remove($connection, $locationKey, null);
            } else {
                $this->write($connection, $locationKey, null, $globalPages);
            }
        }

        foreach ($values as $salesChannelId => $own) {
            $pages = $this->selectedPages($own, $global);

            // Same as "All Sales Channels": keep inheriting, so later changes there still apply
            if ($salesChannelId === '' || $pages === $globalPages) {
                continue;
            }

            $salesChannelBytes = Uuid::fromHexToBytes($salesChannelId);

            if (($pages !== []) !== ($globalPages !== [])) {
                $this->write($connection, $enabledKey, $salesChannelBytes, $pages !== []);
            }

            if ($pages !== []) {
                $this->write($connection, $locationKey, $salesChannelBytes, $pages);
            }
        }
    }

    /**
     * @param array<string, bool> $own former page switches of the scope
     * @param array<string, bool> $inherited former page switches of "All Sales Channels"
     * @return array<int, string>
     */
    private function selectedPages(array $own, array $inherited): array
    {
        $pages = [];
        foreach (array_keys(self::FORMER_PAGE_SWITCHES) as $page) {
            if ($own[$page] ?? $inherited[$page] ?? false) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    private function convertNoneToSwitch(Connection $connection, string $locationKey, string $enabledKey, bool $isNewSwitch): void
    {
        $rows = $this->fetchByKey($connection, self::PREFIX . $locationKey);

        if (!$isNewSwitch) {
            $this->keepInheritedSwitchOn($connection, self::PREFIX . $enabledKey, $rows);
        }

        // A new switch is on for "All Sales Channels" when the page list there has pages
        $globalOn = $this->hasGlobalPages($rows);

        foreach ($rows as $row) {
            $locations = $this->storedValue($row['configuration_value']);
            $salesChannelBytes = is_string($row['sales_channel_id']) ? $row['sales_channel_id'] : null;
            $isGlobal = $salesChannelBytes === null;

            if (!is_array($locations) || $locations === []) {
                continue;
            }

            $pages = $this->pages($locations);

            // Only "None": the button was hidden here. A sales channel only needs its own new
            // switch when "All Sales Channels" is on; otherwise it inherits "off".
            if ($pages === []) {
                if (!$isNewSwitch || $isGlobal || $globalOn) {
                    $this->write($connection, self::PREFIX . $enabledKey, $salesChannelBytes, false);
                }
                $connection->executeStatement('DELETE FROM `system_config` WHERE `id` = :id', ['id' => $row['id']]);
                continue;
            }

            if ($isNewSwitch && ($isGlobal || !$globalOn)) {
                $this->write($connection, self::PREFIX . $enabledKey, $salesChannelBytes, true);
            }

            if ($pages !== $locations) {
                $this->write($connection, self::PREFIX . $locationKey, $salesChannelBytes, $pages);
            }
        }
    }

    /**
     * An existing switch that is on for "All Sales Channels" while its page list there is only
     * "None" gets turned off. Sales channels with their own pages that inherit the switch showed
     * the button, so they get the switch on explicitly before the inherited value changes.
     *
     * @param array<int, array<string, mixed>> $locationRows
     */
    private function keepInheritedSwitchOn(Connection $connection, string $enabledKey, array $locationRows): void
    {
        $globalPages = null;
        $channelsWithPages = [];
        foreach ($locationRows as $row) {
            $locations = $this->storedValue($row['configuration_value']);
            if (!is_array($locations)) {
                continue;
            }

            if ($row['sales_channel_id'] === null) {
                $globalPages = $locations === [] ? null : $this->pages($locations);
            } elseif (is_string($row['sales_channel_id']) && $this->pages($locations) !== []) {
                $channelsWithPages[] = $row['sales_channel_id'];
            }
        }

        if ($globalPages !== [] || $channelsWithPages === []) {
            return;
        }

        $globalEnabled = false;
        $channelsWithOwnSwitch = [];
        foreach ($this->fetchByKey($connection, $enabledKey) as $row) {
            $value = $this->storedValue($row['configuration_value']);

            if ($row['sales_channel_id'] === null) {
                $globalEnabled = $this->toBool($value);
            } elseif (is_string($row['sales_channel_id']) && $this->overridesInheritedValue($value)) {
                $channelsWithOwnSwitch[] = $row['sales_channel_id'];
            }
        }

        if (!$globalEnabled) {
            return;
        }

        foreach (array_diff($channelsWithPages, $channelsWithOwnSwitch) as $salesChannelBytes) {
            $this->write($connection, $enabledKey, $salesChannelBytes, true);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $locationRows
     */
    private function hasGlobalPages(array $locationRows): bool
    {
        foreach ($locationRows as $row) {
            $locations = $this->storedValue($row['configuration_value']);
            if ($row['sales_channel_id'] === null && is_array($locations)) {
                return $this->pages($locations) !== [];
            }
        }

        return false;
    }

    /**
     * Same rule as Shopware's SystemConfigLoader: a sales channel value only replaces the value
     * of "All Sales Channels" when it is a boolean or not empty (0, "0", "" and [] are ignored)
     */
    private function overridesInheritedValue(mixed $value): bool
    {
        return is_bool($value) || !empty($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchByKey(Connection $connection, string $key): array
    {
        return $connection->fetchAllAssociative(
            'SELECT `id`, `configuration_value`, `sales_channel_id` FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => $key]
        );
    }

    /**
     * @param array<mixed> $locations
     * @return array<int, string> the selected pages without "None"
     */
    private function pages(array $locations): array
    {
        return array_values(array_filter(
            $locations,
            static fn (mixed $page): bool => is_string($page) && $page !== 'none'
        ));
    }

    private function storedValue(mixed $configurationValue): mixed
    {
        $decoded = is_string($configurationValue) ? json_decode($configurationValue, true) : null;

        return is_array($decoded) ? ($decoded['_value'] ?? null) : null;
    }

    /**
     * Same interpretation as the storefront used for the former switches
     */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return $value === '1' || strtolower($value) === 'true';
        }

        return false;
    }

    /**
     * Sets a value for one sales channel (null: "All Sales Channels"), replacing any value it has
     */
    private function write(Connection $connection, string $key, ?string $salesChannelBytes, mixed $value): void
    {
        $this->remove($connection, $key, $salesChannelBytes);

        $connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => $key,
            'configuration_value' => json_encode(['_value' => $value]),
            'sales_channel_id' => $salesChannelBytes,
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    private function remove(Connection $connection, string $key, ?string $salesChannelBytes): void
    {
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key AND `sales_channel_id` <=> :salesChannelId',
            ['key' => $key, 'salesChannelId' => $salesChannelBytes]
        );
    }
}
