<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Buckaroo\Push\TypeFactory;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingState;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseRepository;

class PushService
{
    private TypeFactory $factory;

    private LockService $lockService;
    private EngineResponseRepository $engineResponseRepository;
    private ChangeStateService $changeStateService;
    private PushPaymentStateService $pushPaymentStateService;

    public function __construct(
        TypeFactory $factory,
        LockService $lockService,
        EngineResponseRepository $engineResponseRepository,
        ChangeStateService $changeStateService,
        PushPaymentStateService $pushPaymentStateService
    ) {
        $this->factory = $factory;
        $this->lockService = $lockService;
        $this->engineResponseRepository = $engineResponseRepository;
        $this->changeStateService = $changeStateService;
        $this->pushPaymentStateService = $pushPaymentStateService;
    }
    public function process(Request $request, SalesChannelContext $salesChannelContext)
    {
        $processor = $this->factory->get($request);
        $state = new ProcessingState($request);
        $processor->process($state);
        $orderTransactionId = $this->getOrderTransactionId($request, $salesChannelContext);

        if ($orderTransactionId === null) {
            return;
        }

        $lock = $this->lockService->getLock($orderTransactionId);
        if ($lock->acquire()) {
            $this->saveEngineResponse($state, $salesChannelContext->getContext());
            $engineResponses = $this->getOrderTransactions($request, $salesChannelContext);
            $state = $this->pushPaymentStateService->getState(
                $engineResponses,
                $salesChannelContext->getContext(),
                $orderTransactionId
            );
            $this->changeStateService->setState(
                $orderTransactionId,
                $state,
                $salesChannelContext->getContext()
            );
            $lock->release();
        }
    }

    private function canSaveEngineResponse(
        string $signature,
        Context $context
    ) {
        $this->engineResponseRepository->findBySignature($signature, $context) === null;
    }

    /**
     * Save engine response if exists and its not duplicate
     *
     * @param ProcessingState $state
     * @param EngineResponseCollection $engineResponses
     * @param Context $context
     *
     * @return void
     */
    private function saveEngineResponse(
        ProcessingState $state,
        Context $context
    ): void
    {
        if (
            !$this->canSaveEngineResponse(
                $state->getRequest()->getSignature(),
                $context
            )
        ) {
           return;
        }
        $this->engineResponseRepository->upsert([$state->getTransactionData()], $context);
    }

    private function getOrderTransactions(Request $request, SalesChannelContext $salesChannelContext): EngineResponseCollection
    {
        return $this->engineResponseRepository->findByData(
            $request->getOrderTransactionId(),
            $request->getTransactionKey(),
            $request->getRelatedTransaction(),
            $salesChannelContext->getContext()
        );
    }


    private function getOrderTransactionId(Request $request, SalesChannelContext $salesChannelContext): ?string
    {
        $engineResponses = $this->getOrderTransactions($request, $salesChannelContext);

        foreach ($engineResponses as $response) {
            if ($response->getOrderTransactionId() !== null) {
                return $response->getOrderTransactionId();
            }
        }

        return $request->getTransactionKey();
    }
}
