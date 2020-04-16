<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Helpers;

use Symfony\Component\HttpFoundation\Request;

class BkrHelper
{
    /**
     * Retrieve super globals (replaces Request::createFromGlobals)
     *
     * @return Request
     */
    public function getGlobals(): Request
    {
        return new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER);
    }
}
