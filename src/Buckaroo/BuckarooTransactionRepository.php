<?php declare(strict_types=1);

namespace Buckaroo\Shopware6\Buckaroo;

use DateTime;
use Buckaroo\Shopware6\Buckaroo\Entity\Transaction\BuckarooTransactionEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class BuckarooTransactionRepository
{
    /** @var EntityRepositoryInterface */
    private $cardRepository;

    public function __construct(EntityRepositoryInterface $cardRepository)
    {
        $this->cardRepository = $cardRepository;
    }
}
