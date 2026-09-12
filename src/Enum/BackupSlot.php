<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Which rotation a backup belongs to.
 *
 * SLOTS ARE FINITE AND A BACKUP GOES INTO ONE. Taking a backup when every slot of its type
 * is full either fails or replaces an existing one, and which of those happens is
 * BackupReplacementStrategy - not a setting on the server. That pairing is the whole of
 * BinaryLane's backup model and the thing most worth understanding before automating it.
 */
enum BackupSlot: string
{
    /** Scheduled daily. */
    case Daily = 'daily';

    /** Scheduled weekly. */
    case Weekly = 'weekly';

    /** Scheduled monthly. */
    case Monthly = 'monthly';

    /** Taken on demand, kept for at most seven days. */
    case Temporary = 'temporary';

    /**
     * Whether backups in this slot are taken by the schedule rather than on request.
     */
    public function isScheduled(): bool
    {
        return $this !== self::Temporary;
    }
}
