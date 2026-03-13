<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;

class KlarnaMorService
{
    public const ACTION_CANCEL_RESERVATION  = 'CancelReservation';
    public const ACTION_UPDATE_RESERVATION  = 'UpdateReservation';
    public const ACTION_EXTEND_RESERVATION  = 'ExtendReservation';
    public const ACTION_ADD_SHIPPING_INFO   = 'AddShippingInfo';

    public const ALLOWED_ACTIONS = [
        self::ACTION_CANCEL_RESERVATION,
        self::ACTION_UPDATE_RESERVATION,
        self::ACTION_EXTEND_RESERVATION,
        self::ACTION_ADD_SHIPPING_INFO,
    ];

    protected TransactionService $transactionService;

    protected UrlService $urlService;

    protected ClientService $clientService;

    protected TranslatorInterface $translator;

    public function __construct(
        TransactionService $transactionService,
        UrlService $urlService,
        ClientService $clientService,
        TranslatorInterface $translator
    ) {
        $this->transactionService = $transactionService;
        $this->urlService = $urlService;
        $this->clientService = $clientService;
        $this->translator = $translator;
    }

    /**
     * Execute a Klarna MoR DataRequest fulfillment action.
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     * @param string $action One of the ACTION_* constants
     * @param array<mixed> $extraPayload Optional additional payload fields
     *
     * @return array<mixed>
     */
    public function execute(
        Request $request,
        OrderEntity $order,
        Context $context,
        string $action,
        array $extraPayload = []
    ): array {
        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            return [
                'status'  => false,
                'message' => sprintf('Unknown Klarna MoR action: %s', $action),
            ];
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);

        $dataRequestKey = isset($customFields['dataRequestKey']) && is_string($customFields['dataRequestKey'])
            ? $customFields['dataRequestKey']
            : '';

        if (empty($dataRequestKey)) {
            return [
                'status'  => false,
                'message' => $this->translator->trans('buckaroo.klarna.missing_data_request_key'),
            ];
        }

        $payload = array_merge(
            $this->getCommonPayload($request, $order, $dataRequestKey),
            $extraPayload
        );

        $client = $this->getClient($order->getSalesChannelId())
            ->setAction($action)
            ->setPayload($payload);

        $response = $client->execute();

        if ($response->isSuccess()) {
            return [
                'status'  => true,
                'message' => sprintf(
                    'Klarna %s completed successfully for order %s.',
                    $action,
                    $order->getOrderNumber()
                ),
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    /**
     * @param Request $request
     * @param OrderEntity $order
     * @param string $dataRequestKey
     *
     * @return array<mixed>
     */
    private function getCommonPayload(Request $request, OrderEntity $order, string $dataRequestKey): array
    {
        return [
            'order'          => $order->getOrderNumber(),
            'invoice'        => $order->getOrderNumber(),
            'dataRequestKey' => $dataRequestKey,
            'pushURL'        => $this->urlService->getPushUrlForOrder($order),
            'clientIP'       => $this->getIp($request),
            'additionalParameters' => [
                'orderTransactionId' => $this->getLastTransactionId($order),
                'orderId'            => $order->getId(),
            ],
        ];
    }

    /**
     * @param Request $request
     *
     * @return array<mixed>
     */
    private function getIp(Request $request): array
    {
        $remoteIp = $request->getClientIp();

        return [
            'address' => $remoteIp,
            'type'    => IPProtocolVersion::getVersion($remoteIp),
        ];
    }

    private function getLastTransactionId(OrderEntity $order): string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null) {
            throw new \UnexpectedValueException('Cannot find last transaction on order', 1);
        }
        $transaction = $transactions->last();
        if ($transaction === null) {
            throw new \UnexpectedValueException('Cannot find last transaction on order', 1);
        }
        return $transaction->getId();
    }

    private function getClient(string $salesChannelId): Client
    {
        return $this->clientService->get('klarna', $salesChannelId);
    }
}
