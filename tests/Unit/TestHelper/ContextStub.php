<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\TestHelper;

/**
 * Minimal Context stub to avoid loading real Shopware Context class
 * which contains PHP 8.3+ syntax that breaks on PHP 8.2
 *
 * This stub provides the minimal interface needed for unit tests
 * without requiring the actual Context class to be loaded.
 */
class ContextStub
{
    public function getVars(): array
    {
        return [];
    }

    public function getSource(): ?object
    {
        return null;
    }

    public function getScope(): string
    {
        return 'test';
    }

    public function getVersionId(): string
    {
        return 'test-version';
    }

    public function getRuleIds(): array
    {
        return [];
    }
}
