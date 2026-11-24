<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

interface ClientResponseInterface
{
    public function isSuccess(): bool;

    public function isFailed(): bool;

    public function isCanceled(): bool;

    public function isAwaitingConsumer(): bool;

    public function isPendingProcessing(): bool;

    public function isWaitingOnUserInput(): bool;

    public function isRejected(): bool;

    public function isValidationFailure(): bool;

    /* @return boolean
    */
    public function hasRedirect(): bool;

    public function getRedirectUrl(): string;

    /**
     * Get the status code of the Buckaroo response
     *
     * @return int Buckaroo Response status
     */
    public function getStatusCode(): ?int;

    /**
     * Get the HTTP status code of the Buckaroo response
     *
     * @return int|null HTTP status, null when not available
     */
    public function getHttpStatusCode(): ?int;

    public function isTestMode(): bool;

    /**
     * @return mixed
     */
    public function get(string $key);

    /**
     * Get the returned service parameters
     *
     * @return array<mixed>
     */
    public function getServiceParameters(): array;

    public function getSomeError(): string;

    public function getTransactionKey(): string;

    public function getSubCodeMessage(): string;
}
