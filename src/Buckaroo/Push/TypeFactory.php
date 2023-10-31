<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Push;

use Buckaroo\Shopware6\Buckaroo\Push\Request;
use Buckaroo\Resources\Constants\ResponseStatus;
use Buckaroo\Shopware6\Buckaroo\Push\RequestType;
use Buckaroo\Shopware6\Buckaroo\Push\ProcessingFactoryInterface;
use Buckaroo\Shopware6\Buckaroo\Push\Processors\StatusProcessorInterface;

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

    /**
     * Get processor based on type
     *
     * @param Request $request
     *
     * @return StatusProcessorInterface|null
     */
    public function get(Request $request): ?StatusProcessorInterface
    {
        $factory = $this->typeFactories[$this->getType($request)];

        if ($factory === null) {
            return $this->defaultProcessor;
        }

        return $factory->get($request);
    }

    /**
     * Get response type based
     *
     * @param Request $request
     *
     * @return string
     */
    private function getType(Request $request): string
    {
        $type = 'unknown';
        if ($request->getType() == RequestType::PAYMENT) {
            // accept only payments from sw6
            $type = RequestType::PAYMENT;
        }

        if ($this->isTypeRefund($request)) {
            $type = RequestType::REFUND;
        }

        if ($this->isTypeGroup($request)) {
            $type = RequestType::GROUP;
        }

        if ($this->isTypeGiftCard($request)) {
            $type = RequestType::GIFTCARD;
        }

        if ($this->isTypeInvoice($request)) {
            $type = RequestType::INVOICE;
        }

        if ($this->isTypeAuthorize($request)) {
            $type = RequestType::AUTHORIZE;
        }

        if ($this->isTypeCancelAuthorize($request)) {
            $type = RequestType::CANCEL_AUTHORIZE;
        }

        return $type;
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
