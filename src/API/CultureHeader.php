<?php

namespace Buckaroo\Shopware6\API;
use Buckaroo\Shopware6\Helper\CheckoutHelper;

class CultureHeader
{
    /**
     * @return string
     */
    public function getHeader(?string $locale)
    {
        return "Culture: " . CheckoutHelper::getTranslatedLocale($locale);
    }
}
