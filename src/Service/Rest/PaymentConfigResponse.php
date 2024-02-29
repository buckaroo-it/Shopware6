<?php
namespace Buckaroo\Shopware6\Service\Rest;

use Buckaroo\Shopware6\Storefront\Struct\BuckarooStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

class PaymentConfigResponse extends StoreApiResponse
{
    /**
     * @var BuckarooStruct
     */
    protected $object;

    public function __construct(BuckarooStruct $object)
    {
        parent::__construct($object);
    }

    public function getConfig(): BuckarooStruct
    {
        return $this->object;
    }
}
