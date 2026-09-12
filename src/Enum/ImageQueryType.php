<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The `type` filter accepted by the image list endpoint.
 *
 * TWO CASES, NOT THE THREE OF ImageType. There is no way to ask the list endpoint for
 * snapshots or for uploaded images specifically: `distribution` means the base operating
 * system images, and `backup` means everything belonging to the account.
 */
enum ImageQueryType: string
{
    /** Base operating system images. */
    case Distribution = 'distribution';

    /** Images of a server. */
    case Backup = 'backup';
}
