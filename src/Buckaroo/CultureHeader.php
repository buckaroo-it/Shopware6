<?php

namespace Buckaroo\Shopware6\Buckaroo;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;

class CultureHeader
{
    /**
     * @return string
     */
    public function getHeader($locale = false)
    {
        return "Culture: " . CheckoutHelper::getTranslatedLocale($locale);
    }
}
