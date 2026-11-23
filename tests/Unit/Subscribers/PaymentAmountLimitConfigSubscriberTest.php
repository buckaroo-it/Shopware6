<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Subscribers;

use Buckaroo\Shopware6\Service\PaymentAmountLimitConfigValidator;
use Buckaroo\Shopware6\Subscribers\PaymentAmountLimitConfigSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class PaymentAmountLimitConfigSubscriberTest extends TestCase
{
    private const CHANNEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MAX = 'BuckarooPayments.config.afterpayMaxOrderAmount';

    public function testRunsAfterTheAclCheck(): void
    {
        $events = PaymentAmountLimitConfigSubscriber::getSubscribedEvents();

        static::assertSame(['validateConfigSave', -21], $events[KernelEvents::CONTROLLER]);
    }

    public function testBatchSaveIsValidatedPerSalesChannel(): void
    {
        $payload = ['null' => [self::MAX => 500.0], self::CHANNEL => [self::MAX => 400.0]];
        $context = Context::createDefaultContext();

        $validator = $this->createMock(PaymentAmountLimitConfigValidator::class);
        $validator->expects(static::once())->method('validate')->with($payload, $context);

        $request = new Request([], $payload);
        $request->attributes->set('_route', 'api.action.core.save.system-config.batch');
        $request->attributes->set(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT, $context);

        (new PaymentAmountLimitConfigSubscriber($validator))->validateConfigSave($this->event($request));
    }

    public function testSingleSaveUsesTheSalesChannelOfTheQuery(): void
    {
        $validator = $this->createMock(PaymentAmountLimitConfigValidator::class);
        $validator->expects(static::once())->method('validate')
            ->with([self::CHANNEL => [self::MAX => 400.0]], static::isInstanceOf(Context::class));

        $request = new Request(['salesChannelId' => self::CHANNEL], [self::MAX => 400.0]);
        $request->attributes->set('_route', 'api.action.core.save.system-config');

        (new PaymentAmountLimitConfigSubscriber($validator))->validateConfigSave($this->event($request));
    }

    public function testSingleSaveWithoutSalesChannelIsGlobal(): void
    {
        $validator = $this->createMock(PaymentAmountLimitConfigValidator::class);
        $validator->expects(static::once())->method('validate')
            ->with(['null' => [self::MAX => 400.0]], static::isInstanceOf(Context::class));

        $request = new Request([], [self::MAX => 400.0]);
        $request->attributes->set('_route', 'api.action.core.save.system-config');

        (new PaymentAmountLimitConfigSubscriber($validator))->validateConfigSave($this->event($request));
    }

    public function testOtherRoutesAreIgnored(): void
    {
        $validator = $this->createMock(PaymentAmountLimitConfigValidator::class);
        $validator->expects(static::never())->method('validate');

        $request = new Request([], [self::MAX => -1]);
        $request->attributes->set('_route', 'api.action.core.system-config.value');

        (new PaymentAmountLimitConfigSubscriber($validator))->validateConfigSave($this->event($request));
    }

    private function event(Request $request): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn () => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
