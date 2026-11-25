<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\PaymentMethods;

interface PaymentMethodInterface
{
    /**
     * Return Buckaroo key of the payment method.
     */
    public function getBuckarooKey(): string;

    /**
     * Return Buckaroo service version of the payment method.
     */
    public function getVersion(): string;

    /**
     * Return name of the payment method.
     */
    public function getName(): string;

    /**
     * Return the description of the payment method.
     */
    public function getDescription(): string;

    /**
     * Return the payment handler of a plugin.
     */
    public function getPaymentHandler(): string;

    /**
     * Give the location of a twig file to load with the payment method.
     */
    public function getTemplate(): ?string;

    /**
     * Give the location of the payment logo.
     */
    public function getMedia(): string;

    /**
     * Get the German and English translations of a payment method
     *
     * @return array<mixed>
     */
    public function getTranslations(): array;

    /**
     * Get the type that is currently being used.
     */
    public function getType(): string;

    /**
     * Get the canRefund status
     */
    public function canRefund(): bool;

    /**
     * Get the canCapture status
     */
    public function canCapture(): bool;

    public function getTechnicalName(): string;
}
