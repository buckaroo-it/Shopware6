<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

/**
 * Provides the correct Shopware payment service depending on the installed version.
 *
 * Shopware 6.7+ uses PaymentProcessor; 6.5-6.6 used PaymentService (now removed).
 * Both are injected as optional constructor arguments (on-invalid="null" in services.xml)
 * so the factory works across the full supported version range without relying on
 * runtime container lookups, which fail when the services are not public.
 */
class PaymentServiceFactory
{
    public function __construct(
        private readonly ?object $paymentProcessor,
        private readonly ?object $paymentService
    ) {
    }

    /**
     * Return the payment service appropriate for the running Shopware version.
     *
     * @throws \RuntimeException when neither service is available in the container.
     */
    public function getPaymentService(): object
    {
        // Prefer PaymentProcessor (Shopware 6.7+)
        if ($this->paymentProcessor !== null) {
            return $this->paymentProcessor;
        }

        // Fallback: PaymentService (Shopware 6.5-6.6)
        if ($this->paymentService !== null) {
            return $this->paymentService;
        }

        throw new \RuntimeException(
            'No compatible payment service found. '
            . 'Expected Shopware\Core\Checkout\Payment\PaymentProcessor (6.7+) '
            . 'or Shopware\Core\Checkout\Payment\PaymentService (6.5-6.6).'
        );
    }
}
