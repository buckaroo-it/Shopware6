<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Symfony\Component\HttpFoundation\Request as DefaultRequest;

class Request
{
    private DefaultRequest $request;

    public function __construct(DefaultRequest $request)
    {
        $this->request = $request;
    }

    /**
     * Get internal status
     *
     * @return string|null
     */
    public function getStatus(): ?string
    {
        return RequestStatus::fromStatusCode(
            $this->getStatusCode()
        );
    }
    /**
     * Get sw order transaction id
     *
     * @return string|null
     */
    public function getOrderTransactionId(): ?string
    {
        return $this->getString(
            'ADD_orderTransactionId',
            $this->getString(
                'brq_AdditionalParameters_orderTransactionId',
                null
            )
        );
    }

    /**
     * Get request type
     *
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->getString(
            'ADD_type',
            $this->getString(
                'brq_AdditionalParameters_type',
                null
            )
        );
    }

    /**
     * Get transaction type
     *
     * @return string|null
     */
    public function getTransactionType(): ?string
    {
        return $this->getString('brq_transaction_type');
    }

    /**
     * Get transaction key
     *
     * @return string|null
     */
    public function getTransactionKey(): ?string
    {
        return $this->getString(
            'brq_transactions',
            $this->getString(
                'brq_InvoiceKey',
                null
            )
        );
    }

    /**
     * Get service code
     *
     * @return string|null
     */
    public function getServiceCode(): ?string
    {
        return $this->getString(
            'brq_transaction_method',
            $this->getString('brq_primary_service', null)
        );
    }

    /**
     * Get debit amount
     *
     * @return float
     */
    public function getDebitAmount(): float
    {
        return $this->getFloat('brq_amount', 0.0);
    }

    /**
     * Get credit amount
     *
     * @return float
     */
    public function getCreditAmount(): float
    {
        return $this->getFloat('brq_amount_credit', 0.0);
    }

    /**
     * Get request status code
     *
     * @return string|null
     */
    public function getStatusCode(): ?string
    {
        return $this->getString('brq_statuscode');
    }


    /**
     * Get string value
     *
     * @param string $name
     * @param mixed $default
     *
     * @return string|null
     */
    public function getString(string $name, mixed $default = null): ?string
    {
        $value = $this->request->request->get($name, $default);
        if (!is_scalar($value)) {
            return null;
        }
        return (string)$value;
    }

    /**
     * Get float value
     *
     * @param string $name
     * @param mixed $default
     *
     * @return float|null
     */
    public function getFloat(string $name, mixed $default = null): ?float
    {
        $value = $this->request->request->get($name, $default);
        if (!is_scalar($value)) {
            return null;
        }
        return (float)$value;
    }

    public function hasString(string $name, mixed $default = null): bool
    {
        $value = $this->getString($name, $default);
        return is_string($value) && strlen(trim($value)) > 0;
    }

    public function getRelatedTransaction():? string
    {
        return $this->getString(
            'brq_relatedtransaction_refund',
            $this->getString(
                'brq_relatedtransaction_partialpayment',
                null
            )
        );
    }

    public function isTest(): bool
    {
        return $this->getString('brq_test') === 'true';
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return \DateTime::createFromFormat('Y-m-d H:i:s', $this->getString('brq_timestamp'));
    }

    public function isDataRequest(): bool
    {
        return $this->getString('brq_datarequest') !== null;
    }

    public function getSignature(): ?string
    {
        return $this->getString('brq_signature');
    }
}
