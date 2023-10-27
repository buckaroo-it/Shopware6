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
use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Service\FormatRequestParamService;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;

class CaptureService
{

    public const ORDER_IS_AUTHORIZED = 'buckaroo_is_authorize';

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

        $validationErrors = $this->validate($order, $customFields);

        if ($validationErrors !== null) {
            return $validationErrors;
        }

        $client = $this->getClient(
            $paymentCode,
            $order->getSalesChannelId()
        )
            ->setAction('capture')
            ->setPayload(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $order,
                        $this->getValidCustomField($customFields, 'originalTransactionKey')
                    ),
                    $this->getMethodPayload(
                        $order,
                        $customFields
                    )
                ),
            );

        return $this->handleResponse(
            $client->execute(),
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

            $transactionId = $order->getTransactions()?->last()?->getId();

            if ($transactionId !== null) {
                $this->transactionService->saveTransactionData(
                    $transactionId,
                    $context,
                    ['captured' => 1]
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

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
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
        string $transactionKey
    ): array {
        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountDebit'            => $order->getAmountTotal(),
            'currency'               => $this->getCurrencyIso($order),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'originalTransactionKey' => $transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $this->getLastTransactionId($order),
                'orderId' => $order->getId(),//depreciated
                'type' => RequestType::PAYMENT
            ],
        ];
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
        if (in_array($paymentCode, ['Billink', 'klarnakp']) && is_string($paymentCode)) {
            $data = array_merge($data, $this->getArticles($order, $paymentCode));
        }
        if ($paymentCode === 'klarnakp') {
            $data = array_merge($data, ['reservationNumber' => $customFields['reservationNumber']]);
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
    private function validate(OrderEntity $order, array $customFields): ?array
    {
        $error = null;

        if ($order->getAmountTotal() <= 0) {
            $error = [
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
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.already_captured")
            ];
        }

        if (!isset($customFields['originalTransactionKey'])) {
            $error = [
                'status' => false,
                'message' => $this->translator->trans("buckaroo.capture.general_capture_error")
            ];
        }
        return $error;
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
                'price'             => $item['unitPrice'],
                'vatPercentage'     => $item['vatRate'],
            ];
        }
        return [
            'articles' => $articles
        ];
    }
}
