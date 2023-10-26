<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

use Buckaroo\Shopware6\Service\Push\Request;
use Buckaroo\Resources\Constants\ResponseStatus;
use Buckaroo\Shopware6\Service\Push\Processors\StatusProcessorInterface;


class TypeFactory implements ProcessingFactoryInterface
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

    /**
     * @var ProcessingFactoryInterface[]
     */
    private array $typeFactories;

    private ?StatusProcessorInterface $defaultProcessor;

    public function __construct(array $typeFactories, StatusProcessorInterface $defaultProcessor = null)
    {
        $this->typeFactories = $typeFactories;
        $this->defaultProcessor = $defaultProcessor;
    }
    public function get(Request $request): ?StatusProcessorInterface
    {
        $factory = $this->typeFactories[$this->getType($request)];

        if ($factory === null) {
            return $this->defaultProcessor;
        }

        return $factory->get($request);
    }

    private function getType(Request $request): string
    {
        // todo refactor this
        $type = 'unknown';
        if ($this->isTypePayment($request)) {
            $type = self::TYPE_PAYMENT;
        }

        if ($this->isTypeRefund($request)) {
            $type = self::TYPE_REFUND;
        }

        if ($this->isTypeGroup($request)) {
            $type = self::TYPE_GROUP;
        }

        if ($this->isTypeGiftCard($request)) {
            $type = self::TYPE_GIFTCARD;
        }

        if ($this->isTypeInvoice($request)) {
            $type = self::TYPE_INVOICE;
        }

        if ($this->isTypeAuthorize($request)) {
            $type = self::TYPE_AUTHORIZE;
        }

        if ($this->isTypeCancelAuthorize($request)) {
            $type = self::TYPE_CANCEL_AUTHORIZE;
        }
        return $type;
    }

    private function isTypePayment(Request $request): bool
    {
        return $request->getServiceCode() !== null &&
            !$this->isTypeGiftcard($request);
    }

    private function isTypeGiftcard(Request $request): bool
    {
        return $request->hasString('brq_payment') && $request->hasString('brq_relatedtransaction_partialpayment');
    }

    private function isTypeRefund(Request $request): bool
    {
        return $request->getCreditAmount() > 0 &&
            $request->hasString('brq_relatedtransaction_refund');
    }

    private function isTypeGroup(Request $request): bool
    {
        return $request->getTransactionType() === self::TRANSACTION_TYPE_GROUP;
    }

    private function isTypeInvoice(Request $request): bool
    {
        return $request->hasString('brq_InvoiceKey');
    }

    private function isTypeAuthorize(Request $request): bool
    {
        return ($request->isDataRequest() &&
            $request->getServiceCode() === 'klarnaKp'
        ) || in_array($request->getTransactionType(), self::AUTHORIZE_REQUESTS);
    }

    private function isTypeCancelAuthorize(Request $request): bool
    {
        return $request->getCreditAmount() > 0 &&
            in_array($request->getTransactionType(), self::CANCEL_AUTHORIZE_REQUESTS);
    }
}
