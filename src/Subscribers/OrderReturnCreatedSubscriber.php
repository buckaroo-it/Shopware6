<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Commercial\ReturnManagement\Event\OrderReturnCreatedEvent;

class OrderReturnCreatedSubscriber implements EventSubscriberInterface
{

    protected EntityRepository $orderReturnRepository;
    
    public function __construct(EntityRepository $orderReturnRepository)
    {
        $this->orderReturnRepository = $orderReturnRepository;
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents()
    {
        return [
            OrderReturnCreatedEvent::class => 'onReturnCreated',
        ];
    }

    public function onReturnCreated(OrderReturnCreatedEvent $event)
    {
        /** @var OrderReturnEntity */
        $orderReturn = $this->orderReturnRepository->search(
            new Criteria([$event->getOrderReturnId()]), $event->getContext()
        );

        if($orderReturn !== null) {
            $this->createRefund($orderReturn);
        }
    }

    private function createRefund(OrderReturnEntity $orderReturn)
    {

    }
}
