<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Whether an image can be used yet.
 *
 * NOTE THE CAPITALISATION OF `NEW`. It is upper case on the wire and the other three are
 * lower case, which is not a transcription error here - it is what the specification
 * declares. A case-sensitive comparison against `"new"` will not match it.
 */
enum ImageStatus: string
{
    /** Newly created. Upper case on the wire, alone among these. */
    case New = 'NEW';

    /** Usable. */
    case Available = 'available';

    /** Not usable yet - a backup still being taken, an upload still being processed. */
    case Pending = 'pending';

    /** Gone. Still listed, not usable. */
    case Deleted = 'deleted';

    public function isUsable(): bool
    {
        return $this === self::Available;
    }
}
