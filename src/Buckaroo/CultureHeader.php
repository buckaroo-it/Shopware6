<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;

class CultureHeader
{
    /**
     * @return string
     */
    public function getHeader($locale = '')
    {
        return "Culture: " . ($locale ?? CheckoutHelper::getTranslatedLocale($locale));
    }
}
