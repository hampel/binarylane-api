<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Whether an address on a server is reachable from the internet.
 */
enum NetworkType: string
{
    /** Not internet accessible - a VPC address, or a LAN address. */
    case Private = 'private';

    /** Internet accessible. */
    case Public = 'public';

    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
