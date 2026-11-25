<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Service\Exceptions\InvalidOrderAmountLimitException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SystemConfig\SystemConfigCollection;
use Shopware\Core\System\SystemConfig\SystemConfigEntity;

/**
 * Checks minimum / maximum order amount settings before they are saved.
 *
 * A value must be empty (no limit) or a positive amount, and where both limits apply
 * the minimum must be lower than the maximum. The check uses the values that will
 * apply after the save, so a sales channel value is checked together with the value
 * it inherits from "All Sales Channels", and a change for "All Sales Channels" is
 * checked against every sales channel that overrides one of the two values.
 */
class PaymentAmountLimitConfigValidator
{
    private const PREFIX = 'BuckarooPayments.config.';
    private const GLOBAL_SCOPE = '';
    private const LIMIT_KEY_PATTERN = '/^BuckarooPayments\.config\.(\w+?)(MinOrderAmount|MaxOrderAmount)$/';

    /**
     * @param EntityRepository<SystemConfigCollection> $systemConfigRepository
     */
    public function __construct(private readonly EntityRepository $systemConfigRepository)
    {
    }

    /**
     * @param array<string, mixed> $valuesBySalesChannel values to save, keyed by sales
     *                                                   channel id; "All Sales Channels" uses the key "null" or ""
     */
    public function validate(array $valuesBySalesChannel, Context $context): void
    {
        $submitted = [];
        $paymentCodes = [];

        foreach ($valuesBySalesChannel as $salesChannelId => $values) {
            if (!\is_array($values)) {
                continue;
            }
            $scope = self::toScope((string) $salesChannelId);

            foreach ($values as $key => $value) {
                if (!\is_string($key) || !preg_match(self::LIMIT_KEY_PATTERN, $key, $matches)) {
                    continue;
                }

                if (!self::isEmpty($value)) {
                    $amount = PaymentAmountLimitService::parseAmount($value);
                    if ($amount === null || $amount <= 0) {
                        throw InvalidOrderAmountLimitException::invalidAmount($key, self::toSalesChannelId($scope));
                    }
                }

                $submitted[$scope][$key] = $value;
                $paymentCodes[$matches[1]][$scope] = true;
            }
        }

        if ($paymentCodes === []) {
            return;
        }

        $stored = $this->loadStoredValues(array_keys($paymentCodes), $context);

        foreach ($paymentCodes as $paymentCode => $submittedScopes) {
            $minKey = self::PREFIX . $paymentCode . PaymentAmountLimitService::MIN_SUFFIX;
            $maxKey = self::PREFIX . $paymentCode . PaymentAmountLimitService::MAX_SUFFIX;

            $scopes = array_keys($submittedScopes);
            if (isset($submittedScopes[self::GLOBAL_SCOPE])) {
                // a global change also applies to every sales channel with own values
                $scopes = array_merge($scopes, array_keys($stored));
            }

            foreach (array_unique($scopes) as $scope) {
                $scope = (string) $scope;
                $minimum = $this->getEffectiveAmount($minKey, $scope, $submitted, $stored);
                $maximum = $this->getEffectiveAmount($maxKey, $scope, $submitted, $stored);

                if ($minimum !== null && $maximum !== null && $minimum >= $maximum) {
                    throw InvalidOrderAmountLimitException::invalidRange($paymentCode, self::toSalesChannelId($scope));
                }
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $submitted
     * @param array<string, array<string, mixed>> $stored
     */
    private function getEffectiveAmount(string $key, string $scope, array $submitted, array $stored): ?float
    {
        $value = \array_key_exists($key, $submitted[$scope] ?? [])
            ? $submitted[$scope][$key]
            : ($stored[$scope][$key] ?? null);

        if (self::isEmpty($value)) {
            return $scope === self::GLOBAL_SCOPE
                ? null
                : $this->getEffectiveAmount($key, self::GLOBAL_SCOPE, $submitted, $stored);
        }

        return PaymentAmountLimitService::parseAmount($value);
    }

    /**
     * Saved limit values of the given payment methods, per scope.
     *
     * @param list<string> $paymentCodes
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadStoredValues(array $paymentCodes, Context $context): array
    {
        $keys = [];
        foreach ($paymentCodes as $paymentCode) {
            $keys[] = self::PREFIX . $paymentCode . PaymentAmountLimitService::MIN_SUFFIX;
            $keys[] = self::PREFIX . $paymentCode . PaymentAmountLimitService::MAX_SUFFIX;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('configurationKey', $keys));

        $entries = $this->systemConfigRepository->search($criteria, $context)->getEntities();

        $stored = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof SystemConfigEntity) {
                continue;
            }
            $scope = self::toScope((string) $entry->getSalesChannelId());
            $stored[$scope][$entry->getConfigurationKey()] = $entry->getConfigurationValue();
        }

        return $stored;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || (\is_string($value) && trim($value) === '');
    }

    private static function toScope(string $salesChannelId): string
    {
        return $salesChannelId === 'null' ? self::GLOBAL_SCOPE : $salesChannelId;
    }

    private static function toSalesChannelId(string $scope): ?string
    {
        return $scope === self::GLOBAL_SCOPE ? null : $scope;
    }
}
