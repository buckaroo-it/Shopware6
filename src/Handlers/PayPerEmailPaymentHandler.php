<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Handlers;

use Shopware\Core\Checkout\Order\OrderEntity;
use Buckaroo\Shopware6\Service\PayLinkService;
use Buckaroo\Shopware6\PaymentMethods\PayPerEmail;
use Buckaroo\Shopware6\Service\AsyncPaymentService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

class PayPerEmailPaymentHandler extends PaymentHandlerSimple
{
    public string $paymentClass = PayPerEmail::class;

    protected PayLinkService $payLinkService;

    /**
     * Buckaroo constructor.
     */
    public function __construct(
        AsyncPaymentService $asyncPaymentService,
        PayLinkService $payLinkService
    ) {
        parent::__construct($asyncPaymentService);
        $this->payLinkService = $payLinkService;
    }

    /**
     * Get parameters for specific payment method
     *
     * @param OrderEntity $order
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return array<mixed>
     */
    public function getMethodPayload(
        OrderEntity $order,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $paymentCode
    ): array {
        $address  = $this->asyncPaymentService->getBillingAddress($order);
        $salesChannelContextId =  $salesChannelContext->getSalesChannelId();
        return [
            'email' => $dataBag->get(
                'buckaroo_payperemail_CustomerEmail',
                $this->asyncPaymentService->getCustomer($order)->getEmail()
            ),
            'customer'  => [
                'firstName'     =>  $dataBag->get(
                    'buckaroo_payperemail_CustomerFirstName',
                    $address->getFirstName()
                ),
                'lastName'      => $dataBag->get(
                    'buckaroo_payperemail_CustomerLastName',
                    $address->getLastName()
                ),
                'gender' => $dataBag->get('buckaroo_payperemail_gender'),

            ],
            'expirationDate'        => $this->getExpirationDate($salesChannelContextId),
            'paymentMethodsAllowed' => $this->payLinkService
                ->getPayPerEmailPaymentMethodsAllowed(
                    $salesChannelContextId
                ),
            'additionalParameters' => [
                'fromPayPerEmail' => 1
            ]

        ];
    }

    /**
     * Get method action for specific payment method
     *
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentCode
     *
     * @return string
     */
    public function getMethodAction(
        RequestDataBag $dataBag,
        ?SalesChannelContext $salesChannelContext = null,
        ?string $paymentCode = null
    ): string {
        return 'paymentInvitation';
    }

    private function getExpirationDate(string $salesChannelContextId): string
    {
        $payperemailExpireDays = $this->getSetting(
            'payperemailExpireDays',
            $salesChannelContextId
        );

        if (!is_scalar($payperemailExpireDays)) {
            return '';
        }

        return date('Y-m-d', time() + (int)$payperemailExpireDays * 86400);
    }

    /**
     * Override getCommonRequestPayload for PayPerEmail to use non-tokenized URLs (Shopware 6.5)
     * This is necessary because payment tokens are single-use and get invalidated,
     * but PayPerEmail payments happen asynchronously when customer pays via email link
     *
     * @param mixed $orderTransaction
     * @return array<mixed>
     */
    protected function getCommonRequestPayload(
        $orderTransaction,
        \Shopware\Core\Checkout\Order\OrderEntity $order,
        \Shopware\Core\Framework\Validation\DataBag\RequestDataBag $dataBag,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext,
        string $paymentCode,
        ?string $returnUrl
    ): array {
        // Build base payload from parent
        $payload = parent::getCommonRequestPayload(
            $orderTransaction,
            $order,
            $dataBag,
            $salesChannelContext,
            $paymentCode,
            $returnUrl
        );

        // Override returnURL and cancelURL with non-tokenized versions
        $this->replaceUrlsWithNonTokenized($payload, $order);

        return $payload;
    }

    /**
     * Override configureClient to modify payload before sending to Buckaroo (Shopware 6.7+)
     * Replace tokenized returnURL and cancelURL with non-tokenized versions
     *
     * @param \Buckaroo\Shopware6\Buckaroo\Client $client
     * @param string $paymentCode
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * @return void
     */
    protected function configureClient(
        $client,
        string $paymentCode,
        $salesChannelContext
    ): void {
        // Get current payload
        $payload = $client->getPayload();
        
        // Get order from payload to build non-tokenized URLs
        if (isset($payload['additionalParameters']['orderId'])) {
            $orderId = $payload['additionalParameters']['orderId'];
            $order = $this->asyncPaymentService->checkoutHelper->getOrderById(
                $orderId,
                $salesChannelContext->getContext()
            );
            
            if ($order !== null) {
                $this->replaceUrlsWithNonTokenized($payload, $order);
                $client->setPayload($payload);
            }
        }
    }

    /**
     * Replace tokenized URLs with non-tokenized versions
     * Uses custom PayPerEmail return endpoint that accepts both GET and POST
     *
     * @param array<mixed> &$payload
     * @param \Shopware\Core\Checkout\Order\OrderEntity $order
     * @return void
     */
    private function replaceUrlsWithNonTokenized(array &$payload, \Shopware\Core\Checkout\Order\OrderEntity $order): void
    {
        $returnUrl = $this->asyncPaymentService->urlService->forwardToRoute(
            'buckaroo.payperemail.return',
            ['orderId' => $order->getId()]
        );
        $cancelUrl = $this->asyncPaymentService->urlService->forwardToRoute(
            'buckaroo.payperemail.return',
            ['orderId' => $order->getId(), 'cancel' => '1']
        );

        $payload['returnURL'] = $this->asyncPaymentService->urlService->getSaleBaseUrl() . $returnUrl;
        $payload['cancelURL'] = $this->asyncPaymentService->urlService->getSaleBaseUrl() . $cancelUrl;
    }
}
