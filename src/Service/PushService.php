<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\Push\ProcessingState;
use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\Transaction;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseRepository;
use Buckaroo\Shopware6\Entity\OrderData\OrderDataRepository;
use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Composer\Package\Locker;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PushService
{
    private TypeFactory $factory;

    private LockService $lockService;
    private OrderDataRepository $orderDataRepository;
    private EngineResponseRepository $engineResponseRepository;
    private ChangeStateService $changeStateService;

    public function __construct(
        TypeFactory $factory,
        LockService $lockService,
        OrderDataRepository $orderDataRepository,
        EngineResponseRepository $engineResponseRepository,
        ChangeStateService $changeStateService
    ) {
        $this->factory = $factory;
        $this->lockService = $lockService;
        $this->orderDataRepository = $orderDataRepository;
        $this->engineResponseRepository = $engineResponseRepository;
        $this->changeStateService = $changeStateService;
    }
    public function process(Request $request, SalesChannelContext $salesChannelContext)
    {
        $processor = $this->factory->get($request);
        $state = new ProcessingState($request);
        $processor->process($state);

        if ($state->getTransaction() !== null) {
            $this->saveEngineResponse($state->getTransaction());
        }
        $orderTransactionId = $this->getOrderTransactionId($request, $salesChannelContext);

        if ($state->isSkipped() || $orderTransactionId === null) {
            return;
        }

        //todo 

        $lock = $this->lockService->getLock($orderTransactionId);
        if ($lock->acquire()) {
            $this->changeStateService->setState(
                $orderTransactionId,
                $state,
                $salesChannelContext->getContext()
            );
            $lock->release();
        }
    }

    private function saveEngineResponse(Transaction $transaction): void
    {
        $this->engineResponseRepository->save([$transaction->getData()]);
    }


    private function getOrderTransactionId(Request $request, SalesChannelContext $salesChannelContext): ?string
    {
        $engineResponses = $this->engineResponseRepository->findByData(
            $request->getOrderTransactionId(),
            $request->getTransactionKey(),
            $request->getRelatedTransaction(),
            $salesChannelContext->getContext()
        );

        foreach ($engineResponses as $response) {
            if ($response->getOrderTransactionId() !== null) {
                return $response->getOrderTransactionId();
            }
        }

        return null;
    }
}
