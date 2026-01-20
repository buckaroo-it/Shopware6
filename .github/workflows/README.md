# GitHub Actions Workflows

This directory contains automated CI/CD workflows for the BuckarooPayments plugin.

## Workflows

### 🧪 Tests (`tests.yml`)

**Triggers**: Push to `main`/`master`/`develop`, Pull Requests

**What it does**:
- Runs on PHP 8.1, 8.2, and 8.3
- Executes all 752 tests (543 unit + 209 integration)
- Generates code coverage reports
- Uploads coverage to Codecov
- Runs code quality checks

**Jobs**:
1. **test** - Runs test suite on multiple PHP versions
2. **lint** - Code quality and syntax validation
3. **test-summary** - Aggregates results

**Execution time**: ~3-5 minutes per PHP version

### ⚡ Quick Tests (`quick-test.yml`)

**Triggers**: Push to feature branches, Pull Requests

**What it does**:
- Runs on PHP 8.3 only (fastest)
- Executes all tests without coverage
- Provides quick feedback for development

**Execution time**: ~1-2 minutes

## GitHub Status Badges

Add these badges to your repository README:

### Tests Status
```markdown
![Tests](https://github.com/YOUR_USERNAME/YOUR_REPO/actions/workflows/tests.yml/badge.svg)
```

### Quick Tests Status
```markdown
![Quick Tests](https://github.com/YOUR_USERNAME/YOUR_REPO/actions/workflows/quick-test.yml/badge.svg)
```

### Codecov Coverage
```markdown
[![codecov](https://codecov.io/gh/YOUR_USERNAME/YOUR_REPO/branch/main/graph/badge.svg)](https://codecov.io/gh/YOUR_USERNAME/YOUR_REPO)
```

## Local Development

Before pushing code, ensure all tests pass locally:

```bash
# Fast check (unit tests only)
./vendor/bin/phpunit --testsuite Unit

# Complete check (all tests)
./vendor/bin/phpunit

# With coverage
./vendor/bin/phpunit --coverage-html coverage/
```

## Workflow Features

### ✅ Dependency Caching
Composer dependencies are cached to speed up workflow execution.

### ✅ Matrix Testing
Tests run on multiple PHP versions to ensure compatibility:
- PHP 8.1 (minimum supported)
- PHP 8.2
- PHP 8.3 (latest)

### ✅ Coverage Reporting
Coverage reports are generated on PHP 8.3 and uploaded to Codecov.

### ✅ Fail-Fast: Disabled
All PHP versions are tested even if one fails, providing complete test coverage.

## Customization

### Trigger on Different Branches

Edit the `on` section in workflow files:

```yaml
on:
  push:
    branches: [ main, develop, your-branch ]
  pull_request:
    branches: [ main, develop ]
```

### Add More PHP Versions

Edit the matrix in `tests.yml`:

```yaml
matrix:
  php-version:
    - '8.0'  # Add older version
    - '8.1'
    - '8.2'
    - '8.3'
    - '8.4'  # Add newer version
```

### Modify Test Commands

Edit the test steps:

```yaml
- name: Run Tests
  run: vendor/bin/phpunit --testsuite Unit --stop-on-failure
```

## Troubleshooting

### Tests Fail in CI but Pass Locally

1. Check PHP version differences
2. Verify dependencies are installed correctly
3. Check for environment-specific issues
4. Review workflow logs for details

### Slow Workflow Execution

1. Ensure caching is working (check cache hit/miss in logs)
2. Consider using `quick-test.yml` for feature branches
3. Run coverage only on one PHP version

### Coverage Upload Fails

1. Check Codecov token configuration
2. Verify coverage.xml is generated
3. Review Codecov GitHub App permissions

## Support

For issues with workflows:
1. Check GitHub Actions logs
2. Review workflow YAML syntax
3. Test commands locally first
4. Open an issue with workflow logs

## Resources

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Codecov Documentation](https://docs.codecov.com/)

## Troubleshooting CI Issues

### "Cannot open file vendor/autoload.php" Error

**Cause**: The bootstrap path in `phpunit.xml` couldn't find the autoloader.

**Solution**: We use a smart bootstrap file (`tests/bootstrap.php`) that checks both:
- Plugin's own `vendor/autoload.php` (CI/standalone)
- Shopware's `../../../vendor/autoload.php` (local development)

The bootstrap automatically selects the correct path.

### "Package shopware/core not found" Error

**Cause**: Shopware packages aren't available in default Packagist.

**Solution**: The workflows now automatically configure the Shopware package repository:
```yaml
- name: Configure Composer repositories
  run: |
    composer config repositories.shopware composer https://packages.shopware.com
    composer config minimum-stability dev
    composer config prefer-stable true
```

### Platform Requirements Issues

**Cause**: Some Shopware dependencies have strict platform requirements.

**Solution**: We use `--ignore-platform-reqs` flag:
```yaml
composer install --ignore-platform-reqs
```

This allows testing the plugin even if some platform extensions are missing in CI.

### Tests Pass Locally but Fail in CI

**Debug steps**:
1. Check PHP version in CI matches your local version
2. Verify `composer.lock` is committed
3. Check for environment-specific code
4. Review CI logs for specific error messages
5. Test locally with the same PHP version:
   ```bash
   docker run -it --rm -v $(pwd):/app php:8.3-cli /app/vendor/bin/phpunit
   ```

### Slow Dependency Installation

**Optimizations already implemented**:
- ✅ Composer cache (saves ~30-60 seconds)
- ✅ `--prefer-dist` flag (faster downloads)
- ✅ `--no-progress` flag (less output)
- ✅ Parallel PHP version testing

If still slow, check:
- Cache is hitting (look for "Cache restored" in logs)
- Network connectivity to packages.shopware.com
- Consider using quick-test.yml for feature branches

## Recent Fixes (January 2026)

### ✅ Fixed: composer.lock Missing
- **Issue**: CI failed with "No composer.lock file present"
- **Solution**: Changed to `composer update` instead of `install`
- **Impact**: CI now resolves dependencies fresh each run

### ✅ Fixed: PHP 8.1 Incompatibility  
- **Issue**: Shopware 6.6+ requires PHP 8.2+, but we tested on PHP 8.1
- **Solution**: Removed PHP 8.1 from test matrix
- **New Matrix**: PHP 8.2 and 8.3 only

### ✅ Fixed: Bootstrap Path Resolution
- **Issue**: CI couldn't find `vendor/autoload.php`
- **Solution**: Created smart `tests/bootstrap.php` that checks both plugin and Shopware vendor paths
- **Impact**: Tests work in both CI and local Shopware installations

See `.github/CI_FIX.md` for complete details.
