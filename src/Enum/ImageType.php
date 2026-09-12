<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What an image is.
 *
 * DISTINCT FROM ImageQueryType, which is what the image LIST endpoint filters on and has
 * only two cases. This is what an individual image reports itself as. `snapshot` appears
 * here and not there, and the specification notes it is not currently produced.
 */
enum ImageType: string
{
    /** Uploaded by a user. */
    case Custom = 'custom';

    /** A snapshot. Not currently produced - the specification says so explicitly. */
    case Snapshot = 'snapshot';

    /** A backup of a server. */
    case Backup = 'backup';
}
