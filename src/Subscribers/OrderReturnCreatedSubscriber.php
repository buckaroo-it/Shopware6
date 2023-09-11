<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Subscribers;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Buckaroo\Shopware6\Service\ReturnService;
use Shopware\Administration\Notification\NotificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Commercial\ReturnManagement\Event\OrderReturnCreatedEvent;
use Shopware\Commercial\ReturnManagement\Entity\OrderReturn\OrderReturnEntity;

class OrderReturnCreatedSubscriber implements EventSubscriberInterface
{

    protected ?EntityRepository $orderReturnRepository;

    protected ReturnService $returnService;

    protected LoggerInterface $logger;

    protected NotificationService $notificationService;

    public function __construct(
        ?EntityRepository $orderReturnRepository,
        ReturnService $returnService,
        LoggerInterface $logger,
        NotificationService $notificationService
    ) {
        $this->orderReturnRepository = $orderReturnRepository;
        $this->returnService = $returnService;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
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

        if ($this->orderReturnRepository === null) {
            return;
        }

        $criteria = new Criteria([$event->getOrderReturnId()]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.transactions');
        $criteria->addAssociation('order.transactions.paymentMethod');
        $criteria->addAssociation('order.transactions.paymentMethod.plugin');
        /** @var OrderReturnEntity */
        $orderReturn = $this->orderReturnRepository->search(
            $criteria,
            $event->getContext()
        )->first();

        if ($orderReturn !== null) {
            $this->createRefund($orderReturn, $event->getContext());
        }
    }

    private function createRefund(OrderReturnEntity $orderReturn, $context)
    {
        try {
            $response = $this->returnService->refundAll(
                $orderReturn->getOrder(),
                $context,
                $orderReturn->getAmountTotal()
            );

            $this->createNotifications($response, $context);
        } catch (\Throwable $th) {
            $this->logger->debug((string)$th);
        }
    }

    private function createNotifications(array $response, Context $context)
    {
        foreach ($response as $result) {
            $status = 'danger';
            if (isset($result['status']) && $result['status'] === true) {
                $status = 'success';
            }

            $message = "A error has occurred while processing the buckaroo refund";
            if (isset($result['message'])) {
                $message = $result['message'];
            }

            $this->notificationService->createNotification(
                [
                    'id' => Uuid::randomHex(),
                    'status' => $status,
                    'message' => $message,
                    'adminOnly' => true,
                    'requiredPrivileges' => [],
                    'createdByIntegrationId' => null,
                    'createdByUserId' => null,
                ],
                $context
            );
        }
    }
}
