<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Tests\Unit\Storefront\Controller;

use Buckaroo\Shopware6\Storefront\Controller\AbstractPaymentController;
use Shopware\Core\Framework\Validation\DataBag\DataBag;

/**
 * Concrete implementation of AbstractPaymentController for testing
 */
class TestableAbstractPaymentController extends AbstractPaymentController
{
    public function testGetProductData(DataBag $formData): array
    {
        return $this->getProductData($formData);
    }
}
