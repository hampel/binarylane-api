<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked - HTTP 401.
 *
 * THE RESPONSE IS EMPTY. Every operation in the specification declares this status with no
 * content schema at all, so unlike every other failure there is nothing in the body to read:
 * `problem` is null, `messages()` is empty, and the exception's own message is written by
 * this package rather than quoted from the API.
 *
 * Distinct from NotPermittedException, which means the token is real and may not do this.
 */
final class NotAuthenticatedException extends ApiException
{
}
