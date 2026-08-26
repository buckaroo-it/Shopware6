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
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;

class CaptureService
{

    public const ORDER_IS_AUTHORIZED = 'buckaroo_is_authorize';

    /**
     * Transaction custom field holding the unix timestamp at which a capture request
     * was handed to the Buckaroo engine without a definitive synchronous result: the
     * engine answered 791 Pending processing (normal for a Klarna MoR "Pay on
     * reservation"), or the connection failed after the request may already have been
     * accepted. While present and fresh, no new capture request may be sent; the
     * definitive result arrives via push, which records `captured`.
     *
     * The `captured` flag alone cannot deduplicate this: it is only written after a
     * response that reports immediate success, while both the order_delivery.written
     * and state_enter.order_delivery.state.shipped events fire a capture trigger
     * within the same request.
     *
     * IMPORTANT: this marker is deliberately persisted only AFTER the HTTP call
     * returns, never before it. The capture triggers run inside the state-machine's
     * open database transaction; a write to order_transaction before the call would
     * hold a row lock for the full duration of the outbound request. Buckaroo delivers
     * the result push BEFORE answering the API call, and PushController writes the
     * same order_transaction row - so a pre-call write deadlocks the ship action
     * against its own push until the HTTP client times out. Same-request deduplication
     * is handled in memory instead (see $inFlightOrderIds).
     */
    public const CAPTURE_INITIATED = 'captureInitiated';

    /**
     * How long (seconds) the in-flight marker blocks a new capture attempt when no
     * `captured` confirmation has arrived. Prevents a stale marker (e.g. a crash
     * between marker write and HTTP call) from permanently blocking the mandatory
     * Klarna MoR capture-on-shipment flow.
     */
    public const CAPTURE_IN_FLIGHT_SECONDS = 600;

    /**
     * Order ids for which this request already sent (or is currently sending) a
     * capture request. Deduplicates the two capture-on-shipment trigger paths that
     * fire within one PHP request (order_delivery.written and
     * state_enter.order_delivery.state.shipped) without any database write - see the
     * deadlock note on CAPTURE_INITIATED for why this must not be a DB flag.
     *
     * @var array<string, bool>
     */
    private array $inFlightOrderIds = [];

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected UrlService $urlService;

    protected InvoiceService $invoiceService;

    protected FormatRequestParamService $formatRequestParamService;

    protected ClientService $clientService;

    public function __construct(
        TransactionService $transactionService,
        UrlService $urlService,
        InvoiceService $invoiceService,
        FormatRequestParamService $formatRequestParamService,
        TranslatorInterface $translator,
        ClientService $clientService
    ) {
        $this->transactionService = $transactionService;
        $this->urlService = $urlService;
        $this->invoiceService = $invoiceService;
        $this->formatRequestParamService = $formatRequestParamService;
        $this->translator = $translator;
        $this->clientService = $clientService;
    }

    private function getValidCustomField(array $customFields, string $name): string
    {
        if (!isset($customFields[$name]) || !is_string($customFields[$name])) {
            throw new \UnexpectedValueException("Cannot find field `{$name}` on order", 1);
        }
        return $customFields[$name];
    }
    /**
     * Do a buckaroo capture request
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array<mixed>|null
     */
    public function capture(
        Request $request,
        OrderEntity $order,
        Context $context
    ): ?array {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }
        $customFields = $this->transactionService->getCustomFields($order, $context);
        $paymentCode = $this->getValidCustomField($customFields, 'serviceName');
        $validationErrors = $this->validate($order, $customFields, $paymentCode);


        if (in_array($paymentCode, ['klarnakp', 'klarna'])) {
            $action = 'pay';
            $originalTransactionKey = 'false';
        } else {
            $action = 'capture';
            $originalTransactionKey = is_scalar($customFields['originalTransactionKey'])
                ? (string)$customFields['originalTransactionKey']
                : '';
        }
        if ($validationErrors !== null) {
            return $validationErrors;
        }

        // In-memory guard: the ship action fires both order_delivery.written and
        // state_enter.order_delivery.state.shipped in the same request. Never send a
        // second capture for an order this request already captured. Deliberately NOT
        // a database write - see the deadlock note on CAPTURE_INITIATED.
        if (isset($this->inFlightOrderIds[$order->getId()])) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.capture_in_progress")
            ];
        }
        $this->inFlightOrderIds[$order->getId()] = true;

        $client = $this->getClient(
            $paymentCode,
            $order->getSalesChannelId()
        )
            ->setAction($action)
            ->setPayload(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $order,
                        $originalTransactionKey,
                        $action
                    ),
                    $this->getMethodPayload(
                        $order,
                        $customFields
                    )
                ),
            );

        try {
            $response = $client->execute();
        } catch (\Throwable $th) {
            // The connection failed but the engine may still have accepted and
            // processed the capture (e.g. a timeout while the engine waited on its
            // own result push). Persist the in-flight marker - safe now, the
            // outbound call is over - so no retry fires before the push has had the
            // chance to record `captured`; the marker expires after
            // CAPTURE_IN_FLIGHT_SECONDS.
            $transactionId = $this->getLastTransactionIdOrNull($order);
            if ($transactionId !== null) {
                $this->transactionService->saveTransactionData(
                    $transactionId,
                    $context,
                    [self::CAPTURE_INITIATED => time()]
                );
            }
            throw $th;
        }

        return $this->handleResponse(
            $response,
            $order,
            $context,
            $paymentCode
        );
    }


    /**
     * Handle response from payment engine
     *
     * @param ClientResponseInterface $response
     * @param OrderEntity $order
     * @param Context $context
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    private function handleResponse(
        ClientResponseInterface $response,
        OrderEntity $order,
        Context $context,
        string $paymentCode
    ): array {
        $transactionId = $this->getLastTransactionIdOrNull($order);

        if ($response->isSuccess()) {
            if (
                !$this->invoiceService->isInvoiced($order->getId(), $context) &&
                !$this->invoiceService->isCreateInvoiceAfterShipment(
                    false,
                    $paymentCode,
                    $order->getSalesChannelId()
                )
            ) {
                $this->invoiceService->generateInvoice($order, $context);
            }

            if ($transactionId !== null) {
                $this->transactionService->saveTransactionData(
                    $transactionId,
                    $context,
                    ['captured' => 1, self::CAPTURE_INITIATED => 0]
                );
            }

            return [
                'status' => true,
                'message' => $this->translator->trans(
                    "buckaroo.capture.captured_amount",
                    [
                        '%amount%' => $order->getAmountTotal(),
                        '%currency%' => $this->getCurrencyIso($order)
                    ]
                )
            ];
        }

        // The engine accepted the request but processes it asynchronously (791). This
        // is the normal flow for a Klarna MoR "Pay on reservation": the definitive
        // result arrives via push. Persist the in-flight marker (safe post-call) so
        // no second capture is sent in the meantime, and report it as initiated
        // instead of failed.
        if ($response->isPendingProcessing()) {
            if ($transactionId !== null) {
                $this->transactionService->saveTransactionData(
                    $transactionId,
                    $context,
                    [self::CAPTURE_INITIATED => time()]
                );
            }

            return [
                'status' => true,
                'message' => $this->translator->trans("buckaroo.capture.capture_pending"),
            ];
        }

        // Definitive failure: nothing was persisted before the call, so a later
        // shipment event or a manual capture can simply retry.
        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    /**
     * Whether a capture request for this transaction is currently in flight: it was
     * handed to the Buckaroo engine less than CAPTURE_IN_FLIGHT_SECONDS ago and no
     * definitive result has been recorded yet. Shared by the capture-on-shipment
     * triggers (OrderStateChangeEvent) and validate().
     *
     * @param array<mixed> $customFields
     */
    public static function isCaptureInFlight(array $customFields): bool
    {
        $initiatedAt = $customFields[self::CAPTURE_INITIATED] ?? null;

        if (!is_numeric($initiatedAt) || (int)$initiatedAt <= 0) {
            return false;
        }

        return (time() - (int)$initiatedAt) < self::CAPTURE_IN_FLIGHT_SECONDS;
    }

    private function getLastTransactionIdOrNull(OrderEntity $order): ?string
    {
        return $order->getTransactions()?->last()?->getId();
    }

    private function getCurrencyIso(OrderEntity $order): string
    {
        $currency = $order->getCurrency();
        if ($currency !== null) {
            return $currency->getIsoCode();
        }
        return 'EUR';
    }

    /**
     * Get parameters common to all payment methods
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param string $transactionKey
     *
     * @return array<mixed>
     */
    private function getCommonRequestPayload(
        Request $request,
        OrderEntity $order,
        string $transactionKey,
        string $action
    ): array {
        $payload = [
            'order' => $order->getOrderNumber(),
            'invoice' => $order->getOrderNumber(),
            'amountDebit' => $order->getAmountTotal(),
            'currency' => $this->getCurrencyIso($order),
            'pushURL' => $this->urlService->getPushUrlForOrder($order),
            'clientIP' => $this->getIp($request),
            'additionalParameters' => [
                'orderTransactionId' => $this->getLastTransactionId($order),
                'orderId' => $order->getId(),
            ],
        ];

        if ($action == 'capture') {
            $payload['originalTransactionKey'] = $transactionKey;
        }

        return $payload;
    }
    private function getLastTransactionId(OrderEntity $order): string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null) {
            throw new \UnexpectedValueException("Cannot find last transaction on order", 1);
        }
        $transaction = $transactions->last();
        if ($transaction === null) {
            throw new \UnexpectedValueException("Cannot find last transaction on order", 1);
        }
        return $transaction->getId();
    }

    /**
     * Get method specific payloads
     *
     * @param OrderEntity $order
     * @param array<mixed> $customFields
     *
     * @return array<mixed>
     */
    private function getMethodPayload(
        OrderEntity $order,
        array $customFields
    ): array {
        $paymentCode = $customFields['serviceName'];

        $data = [];
        // Klarna (MoR) Pay uses only DataRequestKey — articles were already sent in the Reserve
        // DataRequest and must NOT be repeated here (causes Buckaroo 400).
        if (in_array($paymentCode, ['Billink', 'klarnakp']) && is_string($paymentCode)) {
            $data = array_merge($data, $this->getArticles($order, $paymentCode));
        }
        if ($paymentCode === 'klarnakp') {
            $data = array_merge($data, ['reservationNumber' => $customFields['reservationNumber']]);
        }
        if ($paymentCode === 'klarna') {
            $data = array_merge($data, ['dataRequestKey' => $customFields['dataRequestKey'] ?? '']);
        }

        return $data;
    }

    private function cannotCapture(OrderEntity $order, array $customFields): bool
    {
        $orderCustomFields = $order->getCustomFields();
        if (
            isset($orderCustomFields[self::ORDER_IS_AUTHORIZED]) &&
            $orderCustomFields[self::ORDER_IS_AUTHORIZED] === true
        ) {
            return false;
        }
        return isset($customFields['canCapture']) && $customFields['canCapture'] == 0;
    }

    /**
     * Validate request and return any errors
     *
     * @param OrderEntity $order
     * @param array<mixed> $customFields
     *
     * @return array<mixed>|null
     */
    private function validate(OrderEntity $order, array $customFields, string $paymentCode): ?array
    {

        if ($order->getAmountTotal() <= 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.invalid_amount")
            ];
        }

        if ($this->cannotCapture($order, $customFields)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.capture_not_supported")
            ];
        }

        if (!empty($customFields['captured']) && ($customFields['captured'] == 1)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.already_captured")
            ];
        }

        if (self::isCaptureInFlight($customFields)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.capture_in_progress")
            ];
        }
        return null;
    }

    /**
     * Get buckaroo client
     *
     * @param string $paymentCode
     * @param string $salesChannelId
     *
     * @return Client
     */
    private function getClient(string $paymentCode, string $salesChannelId): Client
    {
        return $this->clientService
            ->get($paymentCode, $salesChannelId);
    }


    /**
     * Get client ip
     *
     * @param Request $request
     *
     * @return array<mixed>
     */
    private function getIp(Request $request): array
    {
        $remoteIp = $request->getClientIp();

        return [
            'address'       =>  $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }

    /**
     * Get articles from order
     *
     * @param OrderEntity $order
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    private function getArticles(OrderEntity $order, string $paymentCode): array
    {
        $lines = $this->formatRequestParamService->getOrderLinesArray($order, $paymentCode);

        $articles = [];

        foreach ($lines as $item) {
            $articles[] = [
                'identifier'        => $item['sku'],
                'description'       => $item['name'],
                'quantity'          => $item['quantity'],
                'price'             => $item['unitPrice']['value'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }
}
