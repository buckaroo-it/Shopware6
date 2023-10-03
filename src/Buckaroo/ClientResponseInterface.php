<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

interface ClientResponseInterface
{
    /**
     * @return bool
     */
    public function isSuccess(): bool;
    /**
     * @return bool
     */
    public function isFailed(): bool;
    /**
     * @return bool
     */
    public function isCanceled(): bool;

    /**
     * @return bool
     */
    public function isAwaitingConsumer(): bool;

    /**
     * @return bool
     */
    public function isPendingProcessing(): bool;
    /**
     * @return bool
     */
    public function isWaitingOnUserInput(): bool;
    /**
     * @return bool
     */
    public function isRejected(): bool;
    /**
     * @return bool
     */
    public function isValidationFailure(): bool;


    /* @return boolean
    */
    public function hasRedirect(): bool;

   /**
    * @return string
    */
    public function getRedirectUrl(): string;

   /**
     * Get the status code of the Buckaroo response
     *
     * @return int Buckaroo Response status
     */
    public function getStatusCode(): ?int;

    public function isTestMode(): bool;

     /**
     * @param string $key
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
}
