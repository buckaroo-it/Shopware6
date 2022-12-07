<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Buckaroo\Shopware6\Buckaroo\BkrClient;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;

class PayLinkService
{
    protected SettingsService $settingsService;

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected UrlService $urlService;

    protected BkrClient $client;

    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService,
        UrlService $urlService,
        TranslatorInterface $translator,
        BkrClient $client
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
        $this->urlService = $urlService;
        $this->translator = $translator;
        $this->client = $client;
    }

    public function createPaylink(
        Request $clientRequest,
        OrderEntity $order
    ) {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return;
        }
        $amount       = $order->getAmountTotal();
        $currency     = $order->getCurrency();
        $customer     = $order->getOrderCustomer();
        $currencyCode = $currency !== null ? $currency->getIsoCode() : 'EUR';

        if ($amount <= 0) {
            return [
                'status' => false,
                'message' => $this->translation->trans("buckaroo-payment.paylink.invalid_amount")
            ];
        }
        $finalizePage = $this->urlService->getReturnUrl('buckaroo.payment.finalize');
        $salesChannelId = $order->getSalesChannelId();

        $request = new TransactionRequest;
        $request->setClientIPAndAgent($clientRequest);
        $request->setServiceAction('PaymentInvitation');
        $request->setDescription('');
        $request->setServiceName('payperemail');
        $request->setAmountCredit(0);
        $request->setAmountDebit($amount);
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency($currencyCode);
        $request->setServiceVersion(1);

        $request->setAdditionalParameter('orderTransactionId', $order->getTransactions()->last()->getId());
        $request->setAdditionalParameter('orderId', $order->getId());
        $request->setAdditionalParameter('fromPayPerEmail', 1);
        $request->setAdditionalParameter('fromPayLink', 1);

        $request->setReturnURL($finalizePage);
        $request->setReturnURLCancel(sprintf('%s?cancel=1', $finalizePage));
        $request->setPushURL($this->urlService->getReturnUrl('buckaroo.payment.push'));

        $request->setServiceParameter('CustomerGender', $customer->getSalutation()->getSalutationKey() == 'mr' ? 1 : 2);
        $request->setServiceParameter('CustomerEmail', $customer->getEmail());
        $request->setServiceParameter('CustomerFirstName', $customer->getFirstName());
        $request->setServiceParameter('CustomerLastName', $customer->getLastName());
        $request->setServiceParameter('MerchantSendsEmail', 'true');
        $request->setServiceParameter('PaymentMethodsAllowed', $this->getPayPerEmailPaymentMethodsAllowed($salesChannelId));

        if ($payperemailExpireDays = $this->settingsService->getSetting('payperemailExpireDays', $salesChannelId)) {
            $request->setServiceParameter('ExpirationDate', date('Y-m-d', time() + $payperemailExpireDays * 86400));
        }

        $this->client->setSalesChannelId($salesChannelId);
        $response = $this->client->post(
            $this->urlService->getTransactionUrl('payperemail', $salesChannelId),
            $request,
            TransactionResponse::class
        );

        if ($response->isSuccess() || $response->isAwaitingConsumer()) {
            if ($parameters = $response->getServiceParameters()) {
                $payLink = $parameters['paylink'];
            }
            if ($payLink) {
                return [
                    'status' => true,
                    'paylink' => $payLink,
                    'message' => $this->translation->trans(
                        "buckaroo-payment.paylink.pay_link",
                        [
                            "%payLink%" => $payLink
                        ]
                    )
                ];
            }
        }


        return [
            'status'  => false,
            'message' => $response->getSubCodeMessageFull() ?? $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }
    public function getPayPerEmailPaymentMethodsAllowed(string $salesChannelId = null)
    {
        $methods = [];
        if ($payperemailAllowed = $this->settingsService->getSetting('payperemailAllowed', $salesChannelId)) {

            foreach ($payperemailAllowed as $item) {
                if ($item == 'giftcard') {
                    if ($allowedgiftcards = $this->settingsService->getSetting('allowedgiftcards', $salesChannelId)) {
                        foreach ($allowedgiftcards as $giftcard) {
                            array_push($methods, $giftcard);
                        }
                    }
                } else {
                    array_push($methods, $item);
                }
            }
        }

        return join(',', $methods);
    }
}
