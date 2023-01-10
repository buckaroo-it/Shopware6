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

    /**
     * Do a buckaroo capture request
     *
     * @param Request $request
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array|null
     */
    public function capture(
        Request $request,
        OrderEntity $order,
        Context $context
    ): ?array
    {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }

        $customFields = $this->transactionService->getCustomFields($order, $context);
        $paymentCode = $customFields['serviceName'];
        $validationErrors = $this->validate($order, $customFields);

        if($validationErrors !== null) {
            return $validationErrors;
        }

        $client = $this->getClient(
            $paymentCode,
            $order->getSalesChannelId()
        );

        return $this->handleResponse(
            $client->execute(
                array_merge_recursive(
                    $this->getCommonRequestPayload(
                        $request,
                        $order,
                        $customFields['originalTransactionKey']
                    ),
                    $this->getMethodPayload(
                        $order,
                        $customFields
                    )
                ),
                'capture'
            ),
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
     * @return array
     */
    private function handleResponse(
        ClientResponseInterface $response,
        OrderEntity $order,
        Context $context,
        string $paymentCode
    ): array
    {
        if ($response->isSuccess()) {
            if (
                !$this->invoiceService->isInvoiced($order->getId(), $context) &&
                !$this->invoiceService->isCreateInvoiceAfterShipment(
                    false,
                    $paymentCode,
                    $order->getSalesChannelId()
                )
            ) {
                $this->invoiceService->generateInvoice($order, $context, $order->getId());
            }

            return [
                'status' => true,
                'message' => $this->translator->trans("buckaroo-payment.capture.captured_amount"),
                'amount' => sprintf(
                    " %s %s",
                    $order->getAmountTotal(),
                    $order->getCurrency()->getIsoCode()
                )
            ];
        }

        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }
    
     /**
     * Get parameters common to all payment methods
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array
     */
    private function getCommonRequestPayload(
        Request $request,
        OrderEntity $order,
        string $transactionKey
    ): array
    {
        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountDebit'            => $order->getAmountTotal(),
            'currency'               => $order->getCurrency()->getIsoCode(),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'originalTransactionKey' => $transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $order->getTransactions()->last()->getId(),
                'orderId' => $order->getId(),
            ],
        ];
    }

    /**
     * Get method specific payloads
     *
     * @param OrderEntity $order
     * @param array $customFields
     *
     * @return array
     */
    private function getMethodPayload(
        OrderEntity $order,
        array $customFields
    ): array {
        $paymentCode = $customFields['serviceName'];

        $data = [];
        if (in_array($paymentCode, ['Billink', 'klarnakp'])) {
            $data = array_merge($data, $this->getArticles($order, $paymentCode));
        }
        if($paymentCode === 'klarnakp') {
            $data = array_merge($data, ['reservationNumber' => $customFields['reservationNumber']]);
        }

        return $data;
    }

    /**
     * Validate request and return any errors
     *
     * @param OrderEntity $order
     * @param array $customFields
     *
     * @return array|null
     */
    private function validate(OrderEntity $order, array $customFields): ?array
    {

        if ($order->getAmountTotal() <= 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.invalid_amount")
            ];
        }

        if ($customFields['canCapture'] == 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.capture_not_supported")
            ];
        }

        if (!empty($customFields['captured']) && ($customFields['captured'] == 1)) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.already_captured")
            ];
        }

        if(!isset($customFields['originalTransactionKey'])) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.capture.general_capture_error")
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
     * @return void
     */
    private function getIp(Request $request)
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
     * @return array
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