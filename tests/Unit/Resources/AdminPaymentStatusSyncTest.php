<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Resources;

use Buckaroo\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Guards the copy of the payment status rules in the administration.
 *
 * The payment method overview and settings pages show whether a method is live or in
 * test mode. They can't call SettingsService, so utils/payment-status.js repeats its
 * rules. If both copies drift apart the admin shows a different mode than the one the
 * shop actually uses, e.g. "Test" while real payments are charged. This test pins the
 * list of methods that share another method's test mode, the part most likely to change.
 */
class AdminPaymentStatusSyncTest extends TestCase
{
    private const ADMIN_PAYMENT_STATUS = 'src/Resources/app/administration/src/utils/payment-status.js';

    public function testAdminSharesTestModeWithTheSameMethodsAsTheBackend(): void
    {
        $this->assertSame(
            $this->sorted(SettingsService::SHARED_ENVIRONMENT_METHODS),
            $this->sorted($this->readAdminSharedEnvironmentMethods()),
            'SHARED_ENVIRONMENT_METHODS in ' . self::ADMIN_PAYMENT_STATUS
            . ' must match SettingsService::SHARED_ENVIRONMENT_METHODS'
        );
    }

    /**
     * @return array<string, string>
     */
    private function readAdminSharedEnvironmentMethods(): array
    {
        $source = (string)file_get_contents($this->pluginPath(self::ADMIN_PAYMENT_STATUS));

        $found = preg_match(
            '/export\s+const\s+SHARED_ENVIRONMENT_METHODS\s*=\s*\{([^}]*)\}/',
            $source,
            $match
        );
        $this->assertSame(1, $found, 'SHARED_ENVIRONMENT_METHODS not found in ' . self::ADMIN_PAYMENT_STATUS);

        preg_match_all('/[\'"]?(\w+)[\'"]?\s*:\s*[\'"](\w+)[\'"]/', $match[1], $pairs, PREG_SET_ORDER);

        $methods = [];
        foreach ($pairs as $pair) {
            $methods[$pair[1]] = $pair[2];
        }

        return $methods;
    }

    /**
     * @param array<string, string> $methods
     *
     * @return array<string, string>
     */
    private function sorted(array $methods): array
    {
        ksort($methods);

        return $methods;
    }

    private function pluginPath(string $relativePath): string
    {
        return __DIR__ . '/../../../' . $relativePath;
    }
}
