<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Buckaroo\Shopware6\Entity\Transaction\BuckarooTransactionEntityRepository;

class CancelPaymentService
{
    protected BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository;

    protected UrlService $urlService;

    protected ClientService $clientService;

    public function __construct(
        BuckarooTransactionEntityRepository $buckarooTransactionEntityRepository,
        UrlService $urlService,
        ClientService $clientService
    ) {
        $this->buckarooTransactionEntityRepository = $buckarooTransactionEntityRepository;
        $this->urlService = $urlService;
        $this->clientService = $clientService;
    }


    /**
     * Do a refund on hanging giftcards
     *
     * @param AsyncPaymentTransactionStruct $transactionStruct
     *
     * @return void
     */
    public function cancel(
        AsyncPaymentTransactionStruct $transactionStruct
    ): void {

        $orderTransaction = $transactionStruct->getOrderTransaction();
        $transactions = $this->buckarooTransactionEntityRepository->findByOrderId($orderTransaction->getOrderId());

        foreach ($transactions as $transaction) {
            if (
                $transaction === null ||
                $transaction->get('statuscode') != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS
            ) {
                continue;
            }

            $order = $transactionStruct->getOrder();
            $amount = $transaction->get('amount');
            $paymentCode = $transaction->get('transaction_method');

            if (
                !is_scalar($amount) ||
                (float)$amount <= 0 ||
                !is_string($paymentCode)
            ) {
                continue;
            }


            $client = $this->getClient(
                'giftcards',
                $order->getSalesChannelId()
            )
                ->setAction('refund')
                ->setPayload(
                    $this->getPayload(
                        $order,
                        $transaction->get('transactions'),
                        $orderTransaction->getId(),
                        (float)$amount,
                        $paymentCode
                    )
                );
            $this->handleResponse(
                $client->execute(),
                $transaction
            );
        }
    }

    protected function handleResponse(
        ClientResponseInterface $response,
        BuckarooTransactionEntity $transaction
    ) {
        if (!$response->isSuccess()) {
            return;
        }

        $amountCredit = $transaction->get('amount_credit');

        if (!is_scalar($amountCredit)) {
            $amountCredit = 0;
        }

        $transactionAmount = $response->get('brq_amount');

        if (!is_scalar($transactionAmount)) {
            return;
        }

        $this->buckarooTransactionEntityRepository
            ->save(
                $transaction->get('id'),
                [
                    'amount_credit' => (string)((float)$amountCredit + (float)$transactionAmount)
                ],
            );
    }


    protected function getCurrencyIso(OrderEntity $order): string
    {
        $currency = $order->getCurrency();
        if ($currency === null) {
            throw new \InvalidArgumentException('Cannot find currency on order');
        }
        return $currency->getIsoCode();
    }

    /**
     * Get request parameters
     *
     * @param OrderEntity $order
     * @param mixed $transactionKey
     * @param string $orderTransactionId
     * @param float $amount
     * @param string $transactionKey
     * 
     * @return array<mixed>
     */
    private function getPayload(
        OrderEntity $order,
        $transactionKey,
        string $orderTransactionId,
        float $amount,
        string $paymentCode
    ): array {

        if (!is_scalar($transactionKey)) {
            $transactionKey = '';
        }

        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountCredit'           => $amount,
            'currency'               => $this->getCurrencyIso($order),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'pushURLFailure'         => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp(),
            'originalTransactionKey' => (string)$transactionKey,
            'additionalParameters'   => [
                'orderTransactionId' => $orderTransactionId,
                'orderId' => $order->getId(),
            ],
            "name" => $paymentCode,
        ];
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
    private function getIp(): array
    {
        $remoteIp = (Request::createFromGlobals())->getClientIp();

        return [
            'address'       =>  $remoteIp,
            'type'          => IPProtocolVersion::getVersion($remoteIp)
        ];
    }
}
