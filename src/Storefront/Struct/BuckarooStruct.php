<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Struct;

use Shopware\Core\Framework\Struct\ArrayStruct;

/**
 * Storefront page extension holding the Buckaroo template variables.
 *
 * Values are kept in the ArrayStruct data array instead of dynamic
 * properties (deprecated since PHP 8.2). Twig keeps resolving
 * page.extensions.buckaroo.<name> through ArrayAccess.
 *
 * No @extends generics: the ArrayStruct template differs per Shopware
 * version (TKey/TValue on 6.5, none on 6.6, TData on 6.7), so no single
 * annotation is valid across the supported range.
 */
class BuckarooStruct extends ArrayStruct
{
    public const EXTENSION_NAME = 'buckaroo';
}
