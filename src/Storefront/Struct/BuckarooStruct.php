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
 * @extends ArrayStruct<array<string, mixed>>
 */
class BuckarooStruct extends ArrayStruct
{
    public const EXTENSION_NAME = 'buckaroo';
}
