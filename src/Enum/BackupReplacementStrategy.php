<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What to do when the backup slot you asked for is already full.
 *
 * THE DEFAULT IS THE ONE THAT FAILS, and that is the right default: `none` uses a free slot
 * and errors when there is none, so a scripted backup that would have silently destroyed the
 * previous one stops instead. The other three destroy something by design - read them as
 * instructions, not as fallbacks.
 *
 * `oldest` and `newest` skip backups that are LOCKED or ATTACHED. So a slot can be full and
 * still un-replaceable, and the call fails the same way `none` would.
 */
enum BackupReplacementStrategy: string
{
    /** Use a free slot, and error when there is none. Destroys nothing. */
    case None = 'none';

    /** Replace the specific backup id given. */
    case Specified = 'specified';

    /** Use a free slot, or replace the oldest unlocked, unattached backup of that type. */
    case Oldest = 'oldest';

    /** Use a free slot, or replace the newest unlocked, unattached backup of that type. */
    case Newest = 'newest';

    /**
     * Whether choosing this can destroy an existing backup.
     */
    public function canReplace(): bool
    {
        return $this !== self::None;
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'Do not replace any existing backup: use a free slot of the provided backup type. If there are no free slots an error will occur.',
            self::Specified => 'Replace the specific backup id provided.',
            self::Oldest => 'Use any free slots of the provided backup type, and if there are none replace the oldest unlocked and un-attached backup of that type.',
            self::Newest => 'Use any free slots of the provided backup type, and if there are none replace the newest unlocked and un-attached backup of that type.',
        };
    }
}
