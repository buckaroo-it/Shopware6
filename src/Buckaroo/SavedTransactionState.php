<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Service\Push\RequestStatus;
use Buckaroo\Shopware6\Entity\IdealQrOrder\EngineResponseEntity;
use Buckaroo\Shopware6\Entity\EngineResponse\EngineResponseCollection;

class SavedTransactionState
{

    public const ACTION_SUBMIT = 'submit';
    public const ACTION_CONFIRM = 'confirm';


    protected EngineResponseCollection $transactions;

    public function __construct(EngineResponseCollection $transactions)
    {
        $this->transactions = $transactions;
    }

    /**
     * Is a simple payment
     *
     * @return boolean
     */
    public function isPayment(): bool
    {
        return $this->getFirstOfType(RequestType::PAYMENT) !== null;
    }

    /**
     * Is a authorization
     *
     * @return boolean
     */
    public function isAuthorized(): bool
    {
        return $this->getFirstOfType(RequestType::AUTHORIZE) !== null;
    }

    /**
     * Is group payment
     *
     * @return boolean
     */
    public function isGroup(): bool
    {
        return $this->getFirstOfType(RequestType::GROUP) !== null;
    }

    /**
     * Has confirmed refunds
     *
     * @return boolean
     */
    public function hasRefunds(): bool
    {
        return count($this->getRefunds()) > 0;
    }

    /**
     * Has confirmed cancellations
     *
     * @return boolean
     */
    public function hasCancellations(): bool
    {
        return count($this->getCancellations()) > 0;
    }

    /**
     * Get confirmed refunds
     *
     * @return EngineResponseCollection
     */
    public function getRefunds(): EngineResponseCollection
    {
        return $this->getSuccessful(
            $this->getWithAction(
                $this->getOfType(RequestType::REFUND),
                self::ACTION_CONFIRM
            )
        );
    }

    /**
     * Get confirmed canceled transactions
     *
     * @return EngineResponseCollection
     */
    public function getCancellations(): EngineResponseCollection
    {
        return $this->getSuccessful(
            $this->getWithAction(
                $this->getOfType(RequestType::CANCEL),
                self::ACTION_CONFIRM
            )
        );
    }

    /**
     * Get confirmed payments
     *
     * @return EngineResponseCollection
     */
    public function getPayments(): EngineResponseCollection
    {
        return $this->getSuccessful(
            $this->getWithAction(
                $this->getOfTypes([
                    RequestType::PAYMENT,
                    RequestType::GIFTCARD,
                ]),
                self::ACTION_CONFIRM
            )
        );
    }

    /**
     * Get confirmed authorization
     *
     * @return EngineResponseEntity|null
     */
    public function getAuthorization(): ?EngineResponseEntity
    {
        return $this->getSuccessful(
            $this->getWithAction(
                $this->getOfType(RequestType::AUTHORIZE),
                self::ACTION_CONFIRM
            )
        )->first();
    }

    private function getFirstOfType(string $type): ?EngineResponseEntity
    {
        return $this->getOfType($type)->first();
    }

    private function getOfType(string $type): EngineResponseCollection
    {
        return $this->transactions->filter(
            static function (EngineResponseEntity $transaction) use ($type) {
                return $transaction->getType() === $type;
            }
        );
    }

    private function getOfTypes(array $types): EngineResponseCollection
    {
        return $this->transactions->filter(
            static function (EngineResponseEntity $transaction) use ($types) {
                return in_array($transaction->getType(), $types);
            }
        );
    }

    private function getWithAction(
        EngineResponseCollection $transactions,
        string $action
    ) {
        return $transactions->filter(
            static function (EngineResponseEntity $transaction) use ($action) {
                return $transaction->getAction() === $action;
            }
        );
    }

    private function getSuccessful(
        EngineResponseCollection $transactions,
    ) {
        return $transactions->filter(
            static function (EngineResponseEntity $transaction) {
                return $transaction->getAction() === RequestStatus::STATUS_SUCCESS;
            }
        );
    }
}
