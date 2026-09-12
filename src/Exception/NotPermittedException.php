<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * The token is valid and does not have the standing for this - HTTP 403.
 *
 * Rare on this API, and that rarity is the useful part: the specification declares 403 on
 * exactly one operation - downloading an image - where it means the account is not permitted
 * to export. Everything else that a permission might have stopped comes back as a 404,
 * because an object belonging to another account is not visible rather than forbidden.
 *
 * So a 403 here is nearly always about what the ACCOUNT may do, not about what the TOKEN may
 * do. BinaryLane API tokens are not scoped.
 */
final class NotPermittedException extends ApiException
{
}
