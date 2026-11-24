<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Transaction\Response\TransactionResponse;
use Psr\Http\Message\ResponseInterface;

class ClientResponse implements ClientResponseInterface
{
    protected TransactionResponse $response;

    public function __construct(TransactionResponse $response)
    {
        $this->response = $response;
    }

    public function isSuccess(): bool
    {
        return $this->response->isSuccess();
    }

    public function isFailed(): bool
    {
        return $this->response->isFailed();
    }

    public function isCanceled(): bool
    {
        return $this->response->isCanceled();
    }

    public function isAwaitingConsumer(): bool
    {
        return $this->response->isAwaitingConsumer();
    }

    public function isPendingProcessing(): bool
    {
        return $this->response->isPendingProcessing();
    }

    public function isWaitingOnUserInput(): bool
    {
        return $this->response->isWaitingOnUserInput();
    }

    public function isRejected(): bool
    {
        return $this->response->isRejected();
    }

    public function isValidationFailure(): bool
    {
        return $this->response->isValidationFailure();
    }

    public function hasRedirect(): bool
    {
        $reqAction = $this->response->get('RequiredAction');

        return is_array($reqAction)
            && !empty($reqAction['RedirectURL'])
            && !empty($reqAction['Name'])
            && $reqAction['Name'] == 'Redirect';
    }

    public function getRedirectUrl(): string
    {
        $reqAction = $this->get('RequiredAction');
        if ($this->hasRedirect() && is_array($reqAction)) {
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

    /**
     * Get the HTTP status code of the Buckaroo response
     *
     * @return int|null HTTP status, null when not available
     */
    public function getHttpStatusCode(): ?int
    {
        $httpResponse = $this->response->getHttpResponse();
        if ($httpResponse instanceof ResponseInterface) {
            return $httpResponse->getStatusCode();
        }

        return null;
    }

    public function isTestMode(): bool
    {
        return $this->get('IsTest') === true;
    }

    /**
     * @return mixed
     */
    public function get(string $key)
    {
        $data = $this->response->data();
        if (is_array($data) && isset($data[$key])) {
            return $data[$key];
        }
    }

    /**
     * @return array<mixed>
     */
    public function getServiceParameters(): array
    {
        return $this->response->getServiceParameters();
    }

    public function getSomeError(): string
    {
        return $this->response->getSomeError();
    }

    public function getTransactionKey(): string
    {
        return $this->response->getTransactionKey();
    }

    public function getSubCodeMessage(): string
    {
        return $this->response->getSubCodeMessage();
    }
}
