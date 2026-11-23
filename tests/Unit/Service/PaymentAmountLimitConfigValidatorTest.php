<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Service;

use Buckaroo\Shopware6\Service\Exceptions\InvalidOrderAmountLimitException;
use Buckaroo\Shopware6\Service\PaymentAmountLimitConfigValidator;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigCollection;
use Shopware\Core\System\SystemConfig\SystemConfigEntity;

class PaymentAmountLimitConfigValidatorTest extends TestCase
{
    private const MIN = 'BuckarooPayments.config.afterpayMinOrderAmount';
    private const MAX = 'BuckarooPayments.config.afterpayMaxOrderAmount';
    private const CHANNEL_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CHANNEL_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function validProvider(): iterable
    {
        yield 'both empty' => [[self::MIN => null, self::MAX => null]];
        yield 'both empty strings' => [[self::MIN => '', self::MAX => '']];
        yield 'only minimum' => [[self::MIN => 50.0, self::MAX => null]];
        yield 'only maximum' => [[self::MIN => null, self::MAX => 500.0]];
        yield 'minimum below maximum' => [[self::MIN => 50.0, self::MAX => 500.0]];
        yield 'decimal amounts' => [[self::MIN => 50.5, self::MAX => 500.99]];
        yield 'decimal comma text' => [[self::MIN => '10,00', self::MAX => '500,99']];
    }

    /**
     * @dataProvider validProvider
     *
     * @param array<string, mixed> $values
     */
    public function testValidSettingsAreAccepted(array $values): void
    {
        $this->createValidator()->validate(['null' => $values], Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidProvider(): iterable
    {
        $range = InvalidOrderAmountLimitException::INVALID_RANGE;
        $amount = InvalidOrderAmountLimitException::INVALID_AMOUNT;

        yield 'minimum above maximum' => [[self::MIN => 500.0, self::MAX => 50.0], $range];
        yield 'minimum equal to maximum' => [[self::MIN => 500.0, self::MAX => 500.0], $range];
        yield 'negative minimum' => [[self::MIN => -1.0], $amount];
        yield 'negative maximum' => [[self::MAX => -0.01], $amount];
        yield 'zero minimum' => [[self::MIN => 0], $amount];
        yield 'zero maximum' => [[self::MAX => 0.0], $amount];
        yield 'zero as text' => [[self::MAX => '0.00'], $amount];
        yield 'not a number' => [[self::MAX => 'abc'], $amount];
        yield 'boolean' => [[self::MIN => true], $amount];
    }

    /**
     * @dataProvider invalidProvider
     *
     * @param array<string, mixed> $values
     */
    public function testInvalidSettingsAreRefused(array $values, string $errorCode): void
    {
        try {
            $this->createValidator()->validate(['null' => $values], Context::createDefaultContext());
            static::fail('The settings were accepted');
        } catch (InvalidOrderAmountLimitException $exception) {
            static::assertSame($errorCode, $exception->getErrorCode());
            static::assertSame(400, $exception->getStatusCode());
        }
    }

    public function testOtherSettingsAreIgnored(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(static::never())->method('search');

        (new PaymentAmountLimitConfigValidator($repository))->validate(
            ['null' => ['BuckarooPayments.config.afterpayFee' => '-5', 'core.basicInformation.shopName' => 'Shop']],
            Context::createDefaultContext()
        );
    }

    public function testSalesChannelValueIsCheckedAgainstTheInheritedValue(): void
    {
        // global minimum 50, channel A sets only a maximum of 40
        $validator = $this->createValidator([['salesChannelId' => null, 'key' => self::MIN, 'value' => 50.0]]);

        $this->expectExceptionObject(InvalidOrderAmountLimitException::invalidRange('afterpay', self::CHANNEL_A));
        $validator->validate(
            [self::CHANNEL_A => [self::MIN => null, self::MAX => 40.0]],
            Context::createDefaultContext()
        );
    }

    public function testSalesChannelOverrideOfOneValueInheritsTheOther(): void
    {
        // global 50 - 800, channel B overrides only the maximum with 600
        $validator = $this->createValidator([
            ['salesChannelId' => null, 'key' => self::MIN, 'value' => 50.0],
            ['salesChannelId' => null, 'key' => self::MAX, 'value' => 800.0],
        ]);

        $validator->validate(
            [self::CHANNEL_B => [self::MIN => null, self::MAX => 600.0]],
            Context::createDefaultContext()
        );

        $this->addToAssertionCount(1);
    }

    public function testGlobalChangeIsCheckedForSalesChannelsWithOwnValues(): void
    {
        // channel B has its own minimum of 50; a global maximum of 40 makes its range invalid
        $validator = $this->createValidator([
            ['salesChannelId' => self::CHANNEL_B, 'key' => self::MIN, 'value' => 50.0],
        ]);

        try {
            $validator->validate(['null' => [self::MIN => null, self::MAX => 40.0]], Context::createDefaultContext());
            static::fail('The settings were accepted');
        } catch (InvalidOrderAmountLimitException $exception) {
            static::assertSame(InvalidOrderAmountLimitException::INVALID_RANGE, $exception->getErrorCode());
            static::assertSame(self::CHANNEL_B, $exception->getParameters()['salesChannelId']);
        }
    }

    public function testGlobalAndSalesChannelValuesInOneBatchAreCheckedTogether(): void
    {
        // stored global minimum 100 is lowered in the same batch that sets channel A's maximum
        $validator = $this->createValidator([['salesChannelId' => null, 'key' => self::MIN, 'value' => 100.0]]);

        $validator->validate(
            [
                'null' => [self::MIN => 10.0],
                self::CHANNEL_A => [self::MAX => 50.0],
            ],
            Context::createDefaultContext()
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<array{salesChannelId: string|null, key: string, value: mixed}> $stored
     */
    private function createValidator(array $stored = []): PaymentAmountLimitConfigValidator
    {
        $collection = new SystemConfigCollection();
        foreach ($stored as $row) {
            $entity = new SystemConfigEntity();
            $entity->setId(Uuid::randomHex());
            $entity->setSalesChannelId($row['salesChannelId']);
            $entity->setConfigurationKey($row['key']);
            $entity->setConfigurationValue($row['value']);
            $collection->add($entity);
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn(new EntitySearchResult(
            'system_config',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext()
        ));

        return new PaymentAmountLimitConfigValidator($repository);
    }
}
