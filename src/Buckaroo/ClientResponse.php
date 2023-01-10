<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Buckaroo\ClientResponseInterface;
use Buckaroo\Transaction\Response\TransactionResponse;

class ClientResponse implements ClientResponseInterface
{
    protected TransactionResponse $response;

    public function __construct(TransactionResponse $response)
    {
        $this->response = $response;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->response->isSuccess();
    }

    /**
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->response->isFailed();
    }

    /**
     * @return bool
     */
    public function isCanceled(): bool
    {
        return $this->response->isCanceled();
    }

    /**
     * @return bool
     */
    public function isAwaitingConsumer(): bool
    {
        return $this->response->isAwaitingConsumer();
    }

    /**
     * @return bool
     */
    public function isPendingProcessing(): bool
    {
        return $this->response->isPendingProcessing();
    }

    /**
     * @return bool
     */
    public function isWaitingOnUserInput(): bool
    {
        return $this->response->isWaitingOnUserInput();
    }

    /**
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->response->isRejected();
    }

    /**
     * @return bool
     */
    public function isValidationFailure(): bool
    {
        return $this->response->isValidationFailure();
    }

    /**
     * @return boolean
     */
    public function hasRedirect(): bool
    {
        $reqAction = $this->response->get('RequiredAction');

        return is_array($reqAction) &&
            !empty($reqAction['RedirectURL']) &&
            !empty($reqAction['Name']) &&
            !empty($reqAction['Name']) &&
            $reqAction['Name'] == 'Redirect';
    }

    /**
     * @return string
     */
    public function getRedirectUrl(): string
    {
        $reqAction = $this->get('RequiredAction');
        if ($this->hasRedirect()) {
            return $reqAction['RedirectURL'];
        }

        return '';
    }

    /**
     * Get the status code of the Buckaroo response
     *
     * @return int Buckaroo Response status
     */
    public function getStatusCode(): ?int
    {
        return $this->response->getStatusCode();
    }

    public function isTestMode(): bool
    {
        return $this->get('IsTest') === true;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        $data = $this->response->data();
        if (isset($data[$key])) {
            return $data[$key];
        }
    }
    public function getServiceParameters(): array
    {
        return $this->response->getServiceParameters();
    }

    public function getSomeError(): string
    {
        return $this->response->getSomeError();
    }
}
