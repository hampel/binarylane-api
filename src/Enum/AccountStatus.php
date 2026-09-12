<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The standing of the account the token belongs to.
 *
 * Worth reading at startup rather than at the first failure: an account in any state but
 * `active` will refuse things for reasons that have nothing to do with the request, and the
 * 400 that comes back says so only obliquely.
 */
enum AccountStatus: string
{
    /** Exists but is not ready for use - most often no payment method. */
    case Incomplete = 'incomplete';

    /** Normal. */
    case Active = 'active';

    /** Under review. BinaryLane's own advice is to contact support urgently. */
    case Warning = 'warning';

    /** No longer permitted to access the service. */
    case Locked = 'locked';

    /**
     * Whether the account is in the one state where everything is expected to work.
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this is a state somebody needs to be told about.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Active;
    }
}
