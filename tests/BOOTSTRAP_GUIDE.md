# Test Bootstrap Guide

The BuckarooPayments plugin provides two bootstrap options for different testing scenarios.

## Bootstrap Options

### 1. Standard Bootstrap (Default) ✅ Recommended

**File**: `tests/bootstrap.php`

**Use when:**
- Running unit tests
- Testing business logic in isolation
- CI/CD environments (GitHub Actions)
- Standalone plugin testing
- Fast test execution needed

**Features:**
- ✅ Smart autoloader detection (plugin or Shopware vendor)
- ✅ Works in CI without full Shopware installation
- ✅ Fast execution (< 1 second)
- ✅ No database required
- ✅ Perfect for 752 unit/integration tests

**Configuration:**
```xml
<!-- phpunit.xml -->
<phpunit bootstrap="tests/bootstrap.php">
```

**Example output:**
```
✓ Using plugin vendor autoload: /path/to/vendor/autoload.php
PHPUnit 9.6.31 by Sebastian Bergmann
...
OK (752 tests, 1017 assertions)
```

### 2. Full Shopware Integration Bootstrap (Advanced)

**File**: `tests/bootstrap-integration.php`

**Use when:**
- Testing with real Shopware kernel
- Need actual database access
- Testing DAL repositories
- Plugin installation/lifecycle testing
- Full end-to-end integration tests

**Features:**
- ✅ Complete Shopware environment
- ✅ Real service container
- ✅ Database connectivity
- ✅ Plugin registration
- ✅ Shopware's TestBootstrapper

**Requirements:**
- Must run within Shopware installation
- DATABASE_URL environment variable
- Shopware test dependencies installed

**Configuration:**
```xml
<!-- phpunit.xml -->
<phpunit bootstrap="tests/bootstrap-integration.php">
```

**Example output:**
```
✓ Shopware test environment bootstrapped
✓ Project directory: /var/www/shopware
✓ BuckarooPayments plugin registered
```

## Comparison Table

| Feature | Standard Bootstrap | Full Integration Bootstrap |
|---------|-------------------|---------------------------|
| **Speed** | ⚡ Very Fast (< 1s) | 🐢 Slower (~3-5s) |
| **Database** | ❌ Not required | ✅ Required |
| **Shopware Install** | ❌ Not required | ✅ Required |
| **CI/CD** | ✅ Perfect | ⚠️ Complex setup |
| **Unit Tests** | ✅ Ideal | ⚠️ Overkill |
| **Integration Tests** | ✅ Good (mocked) | ✅ Best (real) |
| **Service Container** | ⚠️ Mocked in tests | ✅ Real container |
| **DAL Access** | ❌ Mocked | ✅ Real database |

## Switching Between Bootstraps

### Use Standard (Current Default)
```bash
# Already configured in phpunit.xml
./vendor/bin/phpunit
```

### Switch to Full Integration
```xml
<!-- Edit phpunit.xml -->
<phpunit bootstrap="tests/bootstrap-integration.php">
```

```bash
# Run from Shopware root
cd custom/plugins/BuckarooPayments
./vendor/bin/phpunit
```

## CI/CD Recommendations

### GitHub Actions (Current Setup) ✅

Uses **standard bootstrap** - Perfect for CI because:
- No database setup needed
- Fast execution
- Works standalone
- Reliable dependency resolution

### Local Development

**Option 1: Standard (Fast feedback)**
```bash
# Quick unit test check
./vendor/bin/phpunit --testsuite Unit
```

**Option 2: Full Integration (Thorough testing)**
```bash
# Switch bootstrap in phpunit.xml first
./vendor/bin/phpunit --testsuite Integration
```

## Troubleshooting

### "Could not find Shopware project root"

**Solution**: You're using `bootstrap-integration.php` outside Shopware installation.

```bash
# Either switch to standard bootstrap:
# Edit phpunit.xml: bootstrap="tests/bootstrap.php"

# Or run from within Shopware installation
cd /path/to/shopware/custom/plugins/BuckarooPayments
./vendor/bin/phpunit
```

### "DATABASE_URL not set"

**Solution**: Set up database for integration tests.

```bash
# In Shopware root .env file
DATABASE_URL=mysql://user:pass@localhost:3306/shopware_test
```

### "Package shopware/core not found" in CI

**Solution**: Use standard bootstrap (already configured).

The standard bootstrap works in CI because it uses the plugin's own vendor directory.

## Best Practices

### For New Tests

**Unit Test (Recommended)**
```php
// Use standard bootstrap (default)
// tests/Unit/Service/MyServiceTest.php

public function testBusinessLogic(): void
{
    $service = new MyService();
    $result = $service->calculate(100);
    $this->assertEquals(5, $result);
}
```

**Integration Test (Mocked Container)**
```php
// Use standard bootstrap (default)
// tests/Integration/MyIntegrationTest.php

public function testWithMockedContainer(): void
{
    $container = $this->createMock(ContainerInterface::class);
    // Test integration with mocked dependencies
}
```

**Full Integration Test (Real Shopware)**
```php
// Use bootstrap-integration.php
// tests/Integration/FullIntegrationTest.php

public function testWithRealDatabase(): void
{
    $repository = $this->getContainer()->get('order.repository');
    // Test with real Shopware services
}
```

## Migration from Other Plugins

### From Mollie-style Bootstrap

Mollie uses full integration bootstrap. To migrate:

```bash
# Option 1: Keep full integration (like Mollie)
cp tests/bootstrap-integration.php tests/bootstrap.php
# Update phpunit.xml: bootstrap="tests/bootstrap.php"

# Option 2: Use our hybrid approach (recommended)
# Keep standard bootstrap for CI
# Use integration bootstrap only for local full testing
```

### From Simple Bootstrap

Already using standard bootstrap? You're all set! ✅

## Current Setup Summary

**BuckarooPayments Plugin Uses:**
- ✅ Standard bootstrap (default)
- ✅ 752 tests passing
- ✅ Fast CI/CD (< 2 minutes)
- ✅ Works in GitHub Actions
- ✅ No database required

**Optional Enhancement:**
- Use `bootstrap-integration.php` for local deep integration testing
- Switch back to `bootstrap.php` for CI/CD

## References

- [Mollie Bootstrap Example](https://github.com/mollie/Shopware6/blob/master/tests/bootstrap.php)
- [Shopware TestBootstrapper](https://github.com/shopware/platform/blob/trunk/src/Core/TestBootstrapper.php)
- [PHPUnit Bootstrap Documentation](https://docs.phpunit.de/en/9.6/textui.html#command-line-options)

---

**Current Configuration**: ✅ Using `tests/bootstrap.php` (standard)  
**Status**: Working perfectly in CI and local development  
**Recommendation**: Keep current setup unless you need real database access
