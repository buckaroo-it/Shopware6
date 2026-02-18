<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Helpers\UrlHelper;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class UrlService
{
    protected SettingsService $settingsService;

    protected UrlGeneratorInterface $router;

    private TokenFactoryInterfaceV2 $tokenFactory;

    public function __construct(
        SettingsService $settingsService,
        UrlGeneratorInterface $router,
        TokenFactoryInterfaceV2 $tokenFactory
    ) {
        $this->settingsService = $settingsService;
        $this->router = $router;
        $this->tokenFactory = $tokenFactory;
    }

    public function getReturnUrl(string $route): string
    {
        return $this->getSaleBaseUrl() . $this->router->generate(
            $route,
            [],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    public function getSaleBaseUrl(): string
    {
        $checkoutConfirmUrl = $this->router->generate(
            'frontend.checkout.confirm.page',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        return str_replace('/checkout/confirm', '', $checkoutConfirmUrl);
    }
    /**
     * @param string $path
     * @param array<mixed> $parameters
     *
     * @return string
     */
    public function forwardToRoute(string $path, array $parameters = []): string
    {
        return $this->router->generate($path, $parameters);
    }

    public function getRestoreUrl(): string
    {
        return $this->router->generate(
            'frontend.action.buckaroo.redirect',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }


    /**
     * Generate return url with access token
     *
     * @param OrderTransactionEntity $transaction
     * @param integer $paymentFinalizeTransactionTime minutes
     *
     * @return string
     */
    public function generateReturnUrl(OrderTransactionEntity $transaction, int $paymentFinalizeTransactionTime): string
    {
        $finishUrl = $this->router->generate(
            'frontend.checkout.finish.page',
            ['orderId' => $transaction->getOrderId()]
        );


        $errorUrl = $finishUrl;

        $tokenStruct = new TokenStruct(
            null,
            null,
            $transaction->getPaymentMethodId(),
            $transaction->getId(),
            $finishUrl,
            $paymentFinalizeTransactionTime * 60,
            $errorUrl
        );

        $token = $this->tokenFactory->generateToken($tokenStruct);

        return $this->assembleReturnUrl($token);
    }

    /**
     * @param string $token
     *
     * @return string
     */
    private function assembleReturnUrl(string $token): string
    {
        $parameter = ['_sw_payment_token' => $token];

        return $this->router->generate('payment.finalize.transaction', $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
