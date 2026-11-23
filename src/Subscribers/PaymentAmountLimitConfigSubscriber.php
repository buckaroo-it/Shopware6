<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Buckaroo\Shopware6\Service\PaymentAmountLimitConfigValidator;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks the minimum / maximum order amount settings when plugin settings are saved
 * through the Admin API, so invalid limits are refused for every API client and not
 * only in the administration.
 */
class PaymentAmountLimitConfigSubscriber implements EventSubscriberInterface
{
    private const SAVE_ROUTE = 'api.action.core.save.system-config';
    private const BATCH_SAVE_ROUTE = 'api.action.core.save.system-config.batch';

    /**
     * Runs after Shopware's ACL check of the route (priority -20).
     */
    private const PRIORITY = -21;

    public function __construct(private readonly PaymentAmountLimitConfigValidator $validator)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['validateConfigSave', self::PRIORITY],
        ];
    }

    public function validateConfigSave(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        if ($route !== self::SAVE_ROUTE && $route !== self::BATCH_SAVE_ROUTE) {
            return;
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_CONTEXT_OBJECT);
        if (!$context instanceof Context) {
            $context = new Context(new SystemSource());
        }

        $values = $request->request->all();

        if ($route === self::SAVE_ROUTE) {
            $salesChannelId = $request->query->get('salesChannelId');
            $values = [\is_string($salesChannelId) ? $salesChannelId : 'null' => $values];
        }

        $this->validator->validate($values, $context);
    }
}
