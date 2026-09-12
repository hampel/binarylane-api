<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Whether a load balancer is accepting connections.
 */
enum LoadBalancerStatus: string
{
    /** Being built; not yet accepting connections. */
    case New = 'new';

    /** Available. */
    case Active = 'active';

    /** In an errored state. */
    case Errored = 'errored';

    public function isAvailable(): bool
    {
        return $this === self::Active;
    }
}
