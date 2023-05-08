<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Helpers\Constants;

class IPProtocolVersion
{
    public const IPV4 = 0;

    public const IPV6 = 1;

    /**
     * Get the value of the ipaddress version (IPv4 or IPv6)
     *
     * @param  string|null $ipAddress
     * @return int
     */
    public static function getVersion($ipAddress = '0.0.0.0'): int
    {
        if ($ipAddress === null) {
            return self::IPV4;
        }

        return filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? static::IPV6 : static::IPV4;
    }
}
