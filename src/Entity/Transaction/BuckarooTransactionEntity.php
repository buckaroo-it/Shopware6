<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Class BuckarooTransactionEntity
 *
 * The property names below intentionally mirror the storage names used in
 * BuckarooTransactionEntityDefinition (snake_case). The DAL hydrates and writes
 * by property name, and the plugin reads these fields through
 * Entity::get('order_id'), Entity::get('amount_credit'), etc.
 *
 * `created_at` / `updated_at` are declared as regular DateTimeFields in the
 * definition (defaultFields() is intentionally empty), so they are separate from
 * the inherited Entity::$createdAt / Entity::$updatedAt properties.
 *
 * @package Buckaroo\Shopware6\Entity\Transaction
 */
class BuckarooTransactionEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $order_id = null;

    protected ?string $order_transaction_id = null;

    protected ?string $amount = null;

    protected ?string $amount_credit = null;

    protected ?string $currency = null;

    protected ?string $ordernumber = null;

    protected ?string $statuscode = null;

    protected ?string $transaction_method = null;

    protected ?string $transaction_type = null;

    protected ?string $transactions = null;

    protected ?string $relatedtransaction = null;

    protected ?string $type = null;

    protected ?string $refunded_items = null;

    protected ?\DateTimeInterface $created_at = null;

    protected ?\DateTimeInterface $updated_at = null;

    public function getOrderId(): ?string
    {
        return $this->order_id;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->order_id = $orderId;
    }

    public function getOrderTransactionId(): ?string
    {
        return $this->order_transaction_id;
    }

    public function setOrderTransactionId(?string $orderTransactionId): void
    {
        $this->order_transaction_id = $orderTransactionId;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): void
    {
        $this->amount = $amount;
    }

    public function getAmountCredit(): ?string
    {
        return $this->amount_credit;
    }

    public function setAmountCredit(?string $amountCredit): void
    {
        $this->amount_credit = $amountCredit;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getOrdernumber(): ?string
    {
        return $this->ordernumber;
    }

    public function setOrdernumber(?string $ordernumber): void
    {
        $this->ordernumber = $ordernumber;
    }

    public function getStatuscode(): ?string
    {
        return $this->statuscode;
    }

    public function setStatuscode(?string $statuscode): void
    {
        $this->statuscode = $statuscode;
    }

    public function getTransactionMethod(): ?string
    {
        return $this->transaction_method;
    }

    public function setTransactionMethod(?string $transactionMethod): void
    {
        $this->transaction_method = $transactionMethod;
    }

    public function getTransactionType(): ?string
    {
        return $this->transaction_type;
    }

    public function setTransactionType(?string $transactionType): void
    {
        $this->transaction_type = $transactionType;
    }

    public function getTransactions(): ?string
    {
        return $this->transactions;
    }

    public function setTransactions(?string $transactions): void
    {
        $this->transactions = $transactions;
    }

    public function getRelatedtransaction(): ?string
    {
        return $this->relatedtransaction;
    }

    public function setRelatedtransaction(?string $relatedtransaction): void
    {
        $this->relatedtransaction = $relatedtransaction;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getCreatedAtDate(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreatedAtDate(?\DateTimeInterface $createdAt): void
    {
        $this->created_at = $createdAt;
    }

    public function getUpdatedAtDate(): ?\DateTimeInterface
    {
        return $this->updated_at;
    }

    public function setUpdatedAtDate(?\DateTimeInterface $updatedAt): void
    {
        $this->updated_at = $updatedAt;
    }

    /**
     * @return array<mixed>
     */
    public function getRefundedItems(): array
    {
        // Validate and repair data if necessary
        $this->validateAndRepairRefundedItems();

        // At this point, we know the data is valid JSON
        $refundedItems = json_decode((string)$this->refunded_items, true);

        // This should not happen after validation, but added for extra safety
        return is_array($refundedItems) ? $refundedItems : [];
    }

    /**
     * @param array<mixed> $refundedItems
     *
     * @return self
     * @throws \InvalidArgumentException When JSON encoding fails
     */
    public function setRefundedItems(array $refundedItems = []): self
    {
        // Validate input array to ensure it can be safely encoded
        if (!$this->isJsonEncodable($refundedItems)) {
            throw new \InvalidArgumentException('Refunded items contain non-encodable data');
        }

        $encodedItems = json_encode($refundedItems, JSON_THROW_ON_ERROR);

        // Additional safety check (though JSON_THROW_ON_ERROR should handle this)
        if ($encodedItems === false) {
            throw new \InvalidArgumentException(
                sprintf('Failed to encode refunded items: %s', json_last_error_msg())
            );
        }

        $this->refunded_items = $encodedItems;
        return $this;
    }

    /**
     * @param array<mixed> $refundedItems
     *
     * @return self
     */
    public function addRefundedItems(array $refundedItems = []): self
    {
        try {
            $currentItems = $this->getRefundedItems();
            $mergedItems = array_merge($currentItems, $refundedItems);
            $this->setRefundedItems($mergedItems);
        } catch (\InvalidArgumentException $e) {
            // Log the error and continue with current items unchanged
            error_log(sprintf(
                'Failed to add refunded items to BuckarooTransactionEntity: %s',
                $e->getMessage()
            ));
        }

        return $this;
    }

    /**
     * Validates if data can be safely JSON encoded
     *
     * @param mixed $data
     * @return bool
     */
    private function isJsonEncodable($data): bool
    {
        // Check for resources, which cannot be JSON encoded
        if (is_resource($data)) {
            return false;
        }

        // For arrays and objects, recursively check all values
        if (is_iterable($data)) {
            foreach ($data as $value) {
                if (!$this->isJsonEncodable($value)) {
                    return false;
                }
            }
        }

        // Try a test encoding to catch any other issues
        try {
            json_encode($data, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * Validates the integrity of the refunded items data and repairs if necessary
     *
     * @return bool True if data is valid or was successfully repaired
     */
    public function validateAndRepairRefundedItems(): bool
    {
        // Try to decode current data
        if (empty($this->refunded_items)) {
            $this->refunded_items = '[]';
            return true;
        }

        $decoded = json_decode($this->refunded_items, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Data is corrupted, reset to empty array
            error_log(sprintf(
                'Corrupted refunded items data detected and reset in BuckarooTransactionEntity: %s',
                json_last_error_msg()
            ));
            $this->refunded_items = '[]';
            return false;
        }

        // Ensure it's an array
        if (!is_array($decoded)) {
            $this->refunded_items = '[]';
            return false;
        }

        return true;
    }

    /**
     * Get the raw JSON string of refunded items (for debugging purposes)
     *
     * @return string
     */
    public function getRefundedItemsRaw(): string
    {
        return $this->refunded_items ?? '';
    }

    /**
     * Check if refunded items data is valid JSON
     *
     * @return bool
     */
    public function hasValidRefundedItemsData(): bool
    {
        if (empty($this->refunded_items)) {
            return true; // Empty is considered valid
        }

        json_decode($this->refunded_items, true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
