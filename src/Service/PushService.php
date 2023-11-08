<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Shopware6\Service\Push\TypeFactory;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingState;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Entity\OrderData\OrderDataRepository;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseRepository;

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
        $orderTransactionId = $this->getOrderTransactionId($request, $salesChannelContext);

        if ($state->isSkipped() || $orderTransactionId === null) {
            return;
        }

        $lock = $this->lockService->getLock($orderTransactionId);
        if ($lock->acquire()) {

            $engineResponses = $this->getOrderTransactions($request, $salesChannelContext);
            $this->saveEngineResponse($state, $engineResponses, $salesChannelContext->getContext());

            // $state = $this->determineOrderState($engineResponses);
            // $this->changeStateService->setState(
            //     $orderTransactionId,
            //     $state,
            //     $salesChannelContext->getContext()
            // );
            $lock->release();
        }
    }

    /**
     * Determine the state of the payment to be saved
     *
     * @return string|null
     */
    private function determineOrderState(EngineResponseCollection $engineResponses) {
        //todo create push payment state service
    }

    private function canSaveEngineResponse(EngineResponseCollection $engineResponses, string $signature) {
        $engineResponse = $engineResponses->filter(function ($engineResponse) use ($signature) {
            return $engineResponse->getSignature() === $signature;
        });
        return $engineResponse->count() === 0;
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
        EngineResponseCollection $engineResponses,
        Context $context
    ): void
    {
        $transaction = $state->getTransaction();
        if (
            $transaction === null ||
            !$this->canSaveEngineResponse(
                $engineResponses,
                $state->getRequest()->getSignature()
            )
        ) {
           return;
        }
        $this->engineResponseRepository->upsert([$transaction->getData()], $context);
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
