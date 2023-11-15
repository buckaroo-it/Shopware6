<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo\Push;

use Buckaroo\Resources\Constants\ResponseStatus;
use Symfony\Component\HttpFoundation\Request as DefaultRequest;

class Request
{
    public const AUTHORIZE_REQUESTS = [
        'I872',
        'V054',
        'I872',
        'I876',
        'I880',
        'I038',
        'I072',
        'I069',
        'I108'
    ];

    public const CANCEL_AUTHORIZE_REQUESTS = [
        'I877',
        'I881',
        'I039',
        'I092'
    ];

    private const TRANSACTION_TYPE_GROUP = ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_GROUP_TRANSACTION;

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
     * @return string
     */
    public function getType(): string
    {
        $addType = $this->getString(
            'ADD_type',
            $this->getString(
                'brq_AdditionalParameters_type',
                null
            )
        );
        $type = RequestType::UNKNOWN;

        if ($addType == RequestType::PAYMENT) {
            // accept only payments from sw6
            $type = RequestType::PAYMENT;
        }

        if ($this->isTypeRefund()) {
            $type = RequestType::REFUND;
        }

        if ($this->isTypeGroup()) {
            $type = RequestType::GROUP;
        }

        if ($this->isTypeGiftCard()) {
            $type = RequestType::GIFTCARD;
        }

        if ($this->isTypeInvoice()) {
            $type = RequestType::INVOICE;
        }

        if ($this->isTypeAuthorize()) {
            $type = RequestType::AUTHORIZE;
        }

        if ($this->isTypeCancelAuthorize()) {
            $type = RequestType::CANCEL_AUTHORIZE;
        }
        return $type;
    }

    private function isTypeGiftcard(): bool
    {
        return $this->hasString('brq_payment') && $this->hasString('brq_relatedtransaction_partialpayment');
    }

    private function isTypeRefund(): bool
    {
        return $this->getCreditAmount() > 0 &&
            $this->hasString('brq_relatedtransaction_refund');
    }

    private function isTypeGroup(): bool
    {
        return $this->getTransactionType() === self::TRANSACTION_TYPE_GROUP;
    }

    private function isTypeInvoice(): bool
    {
        return $this->hasString('brq_InvoiceKey');
    }

    private function isTypeAuthorize(): bool
    {
        return ($this->isDataRequest() &&
            $this->getServiceCode() === 'klarnaKp'
        ) || in_array($this->getTransactionType(), self::AUTHORIZE_REQUESTS);
    }

    private function isTypeCancelAuthorize(): bool
    {
        return $this->getCreditAmount() > 0 &&
            in_array($this->getTransactionType(), self::CANCEL_AUTHORIZE_REQUESTS);
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

    public function getRelatedTransaction(): ?string
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        $dateString = $this->getString('brq_timestamp');
        if ($dateString === null) {
            return null;
        }
        return \DateTime::createFromFormat('Y-m-d H:i:s', $dateString);
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
