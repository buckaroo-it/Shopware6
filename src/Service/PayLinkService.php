<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Buckaroo\Client;
use Buckaroo\Shopware6\Service\UrlService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Service\TransactionService;
use Buckaroo\Shopware6\Service\Buckaroo\ClientService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Shopware6\Helpers\Constants\IPProtocolVersion;

class PayLinkService
{
    protected SettingsService $settingsService;

    protected TransactionService $transactionService;

    protected TranslatorInterface $translator;

    protected UrlService $urlService;

    protected ClientService $clientService;

    public function __construct(
        SettingsService $settingsService,
        TransactionService $transactionService,
        UrlService $urlService,
        TranslatorInterface $translator,
        ClientService $clientService
    ) {
        $this->settingsService = $settingsService;
        $this->transactionService = $transactionService;
        $this->urlService = $urlService;
        $this->translator = $translator;
        $this->clientService = $clientService;
    }
    /**
     * @param Request $request
     * @param OrderEntity $order
     *
     * @return array<mixed>|null
     */
    public function create(
        Request $request,
        OrderEntity $order
    ): ?array {
        if (!$this->transactionService->isBuckarooPaymentMethod($order)) {
            return null;
        }

        $validationErrors = $this->validate($order);

        if ($validationErrors !== null) {
            return $validationErrors;
        }

        $client = $this->getClientService(
            'payperemail',
            $order->getSalesChannelId()
        )
            ->setAction('paymentInvitation')
            ->setPayload(
                $this->getRequestPayload($request, $order)
            );

        return $this->handleResponse(
            $client->execute()
        );
    }


    /**
     * Handle response from payment engine
     *
     * @param ClientResponseInterface $response
     *
     * @return array<mixed>
     */
    private function handleResponse(
        ClientResponseInterface $response
    ): array {
        if ($response->isSuccess() || $response->isAwaitingConsumer()) {
            $parameters = $response->getServiceParameters();

            if (isset($parameters['paylink']) && is_string($parameters['paylink'])) {
                $payLink = (string)$parameters['paylink'];

                return [
                    'status' => true,
                    'paylink' => $payLink,
                    'message' => $this->translator->trans("buckaroo-payment.paylink.pay_link"),
                    'paylinkhref' =>  sprintf(" <a href=\"%s\">%s</a>", $payLink, $payLink)
                ];
            }
        }


        return [
            'status'  => false,
            'message' => $response->getSomeError(),
            'code'    => $response->getStatusCode(),
        ];
    }

    /**
     * Get request payload
     *
     * @param Request $request
     * @param OrderEntity $order
     *
     * @return array<mixed>
     */
    private function getRequestPayload(
        Request $request,
        OrderEntity $order
    ): array {
        $customer = $order->getOrderCustomer();
        $salesChannelId = $order->getSalesChannelId();

        if ($customer === null) {
            throw new \UnexpectedValueException("Cannot find customer on order", 1);
        }

        $transactions = $order->getTransactions();
        if ($transactions === null) {
            throw new \UnexpectedValueException("Cannot find last transaction on order", 1);
        }

        /** @var \Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity|null */
        $transaction = $transactions->last();

        if ($transaction === null) {
            throw new \UnexpectedValueException("Cannot find last transaction on order", 1);
        }

        $currency = $order->getCurrency();
        if ($currency === null) {
            throw new \UnexpectedValueException("Cannot find currency on order", 1);
        }

        $salutation = $customer->getSalutation();
        if ($salutation === null) {
            throw new \UnexpectedValueException("Cannot find salutation on customer", 1);
        }

        $returnUrl = $this->urlService->generateReturnUrl(
            $transaction,
            $this->getExpireDays($salesChannelId) * 24 * 60
        );

        return [
            'order'                  => $order->getOrderNumber(),
            'invoice'                => $order->getOrderNumber(),
            'amountDebit'            => $order->getAmountTotal(),
            'currency'               => $currency->getIsoCode(),
            'pushURL'                => $this->urlService->getReturnUrl('buckaroo.payment.push'),
            'clientIP'               => $this->getIp($request),
            'returnURL'              => $returnUrl,
            'cancelURL'              => sprintf('%s&cancel=1', $returnUrl),
            'additionalParameters'   => [
                'orderTransactionId' => $transaction->getId(),
                'orderId' => $order->getId(),
                'fromPayPerEmail' => 1,
                'fromPayLink' => 1
            ],
            'email'                 => $customer->getEmail(),
            'expirationDate'        => $this->getExpirationDate($salesChannelId),
            'merchantSendsEmail'    => true,
            'paymentMethodsAllowed' => $this->getPayPerEmailPaymentMethodsAllowed($salesChannelId),
            'customer'              => [
                'gender'        => $salutation->getSalutationKey() == 'mr' ? 1 : 2,
                'firstName'     => $customer->getFirstName(),
                'lastName'      => $customer->getLastName()
            ],
        ];
    }


    /**
     * Validate request and return any errors
     *
     * @param OrderEntity $order
     *
     * @return array<mixed>|null
     */
    private function validate(OrderEntity $order): ?array
    {
        if ($order->getAmountTotal() <= 0) {
            return [
                'status' => false,
                'message' => $this->translator->trans("buckaroo-payment.paylink.invalid_amount")
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
    private function getClientService(string $paymentCode, string $salesChannelId): Client
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
     * Get config expired days
     *
     * @param string|null $salesChannelId
     *
     * @return integer|null
     */
    private function getExpireDays(string $salesChannelId = null): ?int
    {
        $payperemailExpireDays = $this->settingsService->getSetting('payperemailExpireDays', $salesChannelId);

        if (is_scalar($payperemailExpireDays) && intval($payperemailExpireDays) > 0) {
            return intval($payperemailExpireDays);
        }
        return null;
    }

    /**
     * Get expiration date
     *
     * @param string|null $salesChannelId
     *
     * @return mixed
     */
    private function getExpirationDate(string $salesChannelId = null)
    {
        $payperemailExpireDays = $this->getExpireDays($salesChannelId);

        if ($payperemailExpireDays !== null) {
            return date('Y-m-d', time() + intval($payperemailExpireDays) * 86400);
        }
        return '';
    }

    /**
     * Get payments methods allowed
     *
     * @param string|null $salesChannelId
     *
     * @return string
     */
    public function getPayPerEmailPaymentMethodsAllowed(string $salesChannelId = null): string
    {
        $payperemailAllowed = $this->settingsService->getSetting('payperemailAllowed', $salesChannelId);

        if (is_array($payperemailAllowed)) {
            if (in_array('giftcard', $payperemailAllowed)) {
                $allowedgiftcards = $this->settingsService->getSetting('allowedgiftcards', $salesChannelId);
                if (is_array($allowedgiftcards)) {
                    $payperemailAllowed = array_merge($payperemailAllowed, $allowedgiftcards);
                }
            }
            return join(',', $payperemailAllowed);
        }
        return '';
    }
}
