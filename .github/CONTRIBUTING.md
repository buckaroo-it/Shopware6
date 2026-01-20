# Contributing to BuckarooPayments Plugin

Thank you for your interest in contributing to the BuckarooPayments Shopware 6 plugin!

## Getting Started

1. Fork the repository
2. Clone your fork locally
3. Create a new branch for your feature or bugfix
4. Make your changes
5. Run the test suite
6. Submit a pull request

## Development Setup

### Prerequisites

- PHP 8.1, 8.2, or 8.3
- Composer 2.x
- Shopware 6.5+ environment (for full integration testing)

### Installing Dependencies

```bash
cd custom/plugins/BuckarooPayments
composer install
```

## Testing

We maintain a comprehensive test suite with both unit and integration tests.

### Running All Tests

```bash
./vendor/bin/phpunit
```

### Running Specific Test Suites

```bash
# Unit tests only (fast)
./vendor/bin/phpunit --testsuite Unit

# Integration tests only
./vendor/bin/phpunit --testsuite Integration

# Specific test file
./vendor/bin/phpunit tests/Unit/Service/SettingsServiceTest.php
```

### Test Coverage

```bash
# Generate HTML coverage report
./vendor/bin/phpunit --coverage-html coverage/

# View coverage
open coverage/index.html
```

## Testing Guidelines

All contributions must include appropriate tests. Follow these principles:

### ✅ DO:
- Write tests for all new features and bug fixes
- Test public behavior, not implementation details
- Use descriptive test method names
- Follow the AAA pattern (Arrange, Act, Assert)
- Mock external dependencies (APIs, databases for unit tests)
- Test edge cases (null, empty, invalid inputs)

### ❌ DON'T:
- Test private methods
- Test framework code
- Create placeholder/empty tests
- Mock the class under test
- Write tests that depend on execution order

### Example Test Structure

```php
public function testCalculateFeeReturnsCorrectAmountForFixedFee(): void
{
    // Arrange
    $service = new FeeCalculator();
    $orderTotal = 100.00;
    
    // Act
    $result = $service->calculateFee($orderTotal, 5.00, false);
    
    // Assert
    $this->assertEquals(5.00, $result);
}
```

## Code Quality

### PHP Syntax Check

```bash
find src tests -name "*.php" -print0 | xargs -0 -n1 php -l
```

### Static Analysis (if configured)

```bash
vendor/bin/phpstan analyze
```

## Continuous Integration

Our CI pipeline runs automatically on:
- Every push to `main`, `master`, or `develop` branches
- Every pull request

The CI pipeline:
- ✅ Tests on PHP 8.1, 8.2, and 8.3
- ✅ Runs all 752 tests
- ✅ Generates code coverage reports
- ✅ Checks code quality
- ✅ Validates PHP syntax

## Pull Request Process

1. **Create a descriptive PR title**
   - ✅ Good: "Add support for new payment method X"
   - ❌ Bad: "Updates"

2. **Provide a clear description**
   - What does this PR do?
   - Why is it needed?
   - How was it tested?

3. **Ensure all tests pass**
   - All 752 tests must pass
   - No new linter errors
   - Maintain or improve coverage

4. **Update documentation**
   - Add/update docblocks
   - Update README if needed
   - Add test documentation

5. **Keep PRs focused**
   - One feature/fix per PR
   - Avoid mixing refactoring with features

## Test Suite Statistics

Current test coverage:
- **Total Tests**: 752
- **Total Assertions**: 1,017
- **Unit Tests**: 543 tests, 678 assertions
- **Integration Tests**: 209 tests, 339 assertions
- **Payment Methods**: 35 fully tested
- **Services**: 5 core services tested

## Questions?

If you have questions about contributing, testing, or anything else, please:
- Open an issue for discussion
- Check existing issues and PRs
- Review the test suite in `tests/` for examples

## Code of Conduct

- Be respectful and professional
- Provide constructive feedback
- Focus on the code, not the person
- Help others learn and grow

Thank you for contributing! 🎉
