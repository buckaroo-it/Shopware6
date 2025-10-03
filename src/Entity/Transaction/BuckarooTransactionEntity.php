<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Entity\Transaction;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

/**
 * Class BuckarooTransactionEntity
 *
 * @package Buckaroo\Shopware6\Entity\Transaction
 */
class BuckarooTransactionEntity extends Entity
{
    use EntityIdTrait;

    private string $refundedItems = '[]';

    /**
     * @return array<mixed>
     */
    public function getRefundedItems(): array
    {
        // Validate and repair data if necessary
        $this->validateAndRepairRefundedItems();
        
        // At this point, we know the data is valid JSON
        $refundedItems = json_decode($this->refundedItems, true);
        
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
        
        $this->refundedItems = $encodedItems;
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
        if (empty($this->refundedItems)) {
            $this->refundedItems = '[]';
            return true;
        }

        $decoded = json_decode($this->refundedItems, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Data is corrupted, reset to empty array
            error_log(sprintf(
                'Corrupted refunded items data detected and reset in BuckarooTransactionEntity: %s',
                json_last_error_msg()
            ));
            $this->refundedItems = '[]';
            return false;
        }
        
        // Ensure it's an array
        if (!is_array($decoded)) {
            $this->refundedItems = '[]';
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
        return $this->refundedItems;
    }

    /**
     * Check if refunded items data is valid JSON
     *
     * @return bool
     */
    public function hasValidRefundedItemsData(): bool
    {
        if (empty($this->refundedItems)) {
            return true; // Empty is considered valid
        }

        json_decode($this->refundedItems, true);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
