<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Helpers\GatewayHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Minimum and maximum order amount per Buckaroo payment method.
 *
 * The limits are the `<key>MinOrderAmount` / `<key>MaxOrderAmount` plugin settings,
 * read per sales channel so Shopware's own inheritance from "All Sales Channels"
 * applies. An empty setting means no limit; both boundaries are inclusive.
 */
class PaymentAmountLimitService
{
    public const MIN_SUFFIX = 'MinOrderAmount';
    public const MAX_SUFFIX = 'MaxOrderAmount';

    /**
     * Methods without a configuration card of their own, mapped to the card whose
     * limits they use.
     *
     * @var array<string, string>
     */
    public const LIMIT_SETTING_KEYS = [
        'klarnain' => 'klarna',
    ];

    private const DEFAULT_DECIMALS = 2;

    /**
     * @var array<string, string>|null handler class => Buckaroo key
     */
    private ?array $keysByHandler = null;

    /**
     * @var array<string, true>
     */
    private array $reportedInvalidRanges = [];

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Buckaroo key of a payment method, or null for payment methods of other providers.
     */
    public function getBuckarooKey(PaymentMethodEntity $paymentMethod): ?string
    {
        return $this->getKeysByHandler()[$paymentMethod->getHandlerIdentifier()] ?? null;
    }

    /**
     * Whether the cart total is within the limits of the payment method.
     * Payment methods of other providers are always allowed.
     */
    public function isAllowedForCart(
        PaymentMethodEntity $paymentMethod,
        Cart $cart,
        SalesChannelContext $context
    ): bool {
        $buckarooKey = $this->getBuckarooKey($paymentMethod);
        if ($buckarooKey === null) {
            return true;
        }

        return $this->isAmountAllowed(
            $buckarooKey,
            $cart->getPrice()->getTotalPrice(),
            $context->getSalesChannelId(),
            $context->getTotalRounding()->getDecimals()
        );
    }

    /**
     * Whether the order total is within the limits of the payment method.
     * Payment methods of other providers are always allowed.
     */
    public function isAllowedForOrder(PaymentMethodEntity $paymentMethod, OrderEntity $order): bool
    {
        $buckarooKey = $this->getBuckarooKey($paymentMethod);
        if ($buckarooKey === null) {
            return true;
        }

        return $this->isAmountAllowed(
            $buckarooKey,
            $this->getOrderAmount($order),
            $order->getSalesChannelId(),
            $order->getTotalRounding()?->getDecimals() ?? self::DEFAULT_DECIMALS
        );
    }

    /**
     * Order total without a Buckaroo fee added by an earlier payment attempt, so an
     * order is checked against the same amount as the cart it was created from.
     */
    public function getOrderAmount(OrderEntity $order): float
    {
        $amount = $order->getAmountTotal();
        $existingFee = $order->getCustomFieldsValue('buckarooFee');

        if ($existingFee !== null && is_numeric($existingFee)) {
            $amount -= (float) $existingFee;
        }

        return max(0.0, $amount);
    }

    public function isAmountAllowed(
        string $buckarooKey,
        float $amount,
        ?string $salesChannelId,
        int $decimals = self::DEFAULT_DECIMALS
    ): bool {
        [$minimum, $maximum] = $this->getLimits($buckarooKey, $salesChannelId);

        if ($minimum === null && $maximum === null) {
            return true;
        }

        if ($minimum !== null && $maximum !== null && $minimum >= $maximum) {
            $this->reportInvalidRange($buckarooKey, $salesChannelId);
        }

        $amountInMinorUnits = self::toMinorUnits($amount, $decimals);

        if ($minimum !== null && $amountInMinorUnits < self::toMinorUnits($minimum, $decimals)) {
            return false;
        }

        if ($maximum !== null && $amountInMinorUnits > self::toMinorUnits($maximum, $decimals)) {
            return false;
        }

        return true;
    }

    public function hasLimits(string $buckarooKey, ?string $salesChannelId): bool
    {
        [$minimum, $maximum] = $this->getLimits($buckarooKey, $salesChannelId);

        return $minimum !== null || $maximum !== null;
    }

    /**
     * @return array{0: float|null, 1: float|null} minimum and maximum, null when not set
     */
    public function getLimits(string $buckarooKey, ?string $salesChannelId): array
    {
        $settingKey = self::getSettingKey($buckarooKey);

        return [
            self::parseAmount($this->settingsService->getSetting($settingKey . self::MIN_SUFFIX, $salesChannelId)),
            self::parseAmount($this->settingsService->getSetting($settingKey . self::MAX_SUFFIX, $salesChannelId)),
        ];
    }

    /**
     * Prefix of the limit settings for a Buckaroo key.
     */
    public static function getSettingKey(string $buckarooKey): string
    {
        return self::LIMIT_SETTING_KEYS[$buckarooKey] ?? $buckarooKey;
    }

    /**
     * A configured amount as a number; null when empty or not a number.
     * A decimal comma is accepted.
     */
    public static function parseAmount(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return is_finite($amount) ? $amount : null;
    }

    /**
     * Whole minor units (cents for EUR), so boundary amounts compare exactly.
     */
    private static function toMinorUnits(float $amount, int $decimals): int
    {
        return (int) round($amount * (10 ** max(0, $decimals)));
    }

    /**
     * @return array<string, string>
     */
    private function getKeysByHandler(): array
    {
        if ($this->keysByHandler !== null) {
            return $this->keysByHandler;
        }

        $this->keysByHandler = [];
        foreach (GatewayHelper::GATEWAYS as $gatewayClass) {
            $gateway = new $gatewayClass();
            $this->keysByHandler[$gateway->getPaymentHandler()] = $gateway->getBuckarooKey();
        }

        return $this->keysByHandler;
    }

    private function reportInvalidRange(string $buckarooKey, ?string $salesChannelId): void
    {
        $id = $buckarooKey . '|' . ($salesChannelId ?? '');
        if (isset($this->reportedInvalidRanges[$id])) {
            return;
        }
        $this->reportedInvalidRanges[$id] = true;

        $this->logger->warning(
            'Buckaroo: the minimum order amount of ' . $buckarooKey
            . ' is not lower than its maximum order amount; the limits are applied as configured'
        );
    }
}
