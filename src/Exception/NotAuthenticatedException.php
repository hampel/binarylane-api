<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * The token was missing, malformed, expired or revoked - HTTP 401.
 *
 * THE RESPONSE IS EMPTY, AND THAT IS MEASURED RATHER THAN INFERRED. Every operation in the
 * specification declares this status with no content schema, and the live API confirms it:
 * on 12 September 2026, both a made-up bearer token and no Authorization header at all
 * answered `HTTP 401` with `content-length: 0` and no body. The two are byte-identical, so
 * nothing in the response distinguishes "wrong token" from "no token".
 *
 * Unlike every other failure, then, there is nothing in the body to read: `problem` is null,
 * `messages()` is empty, and the exception's own message is written by this package rather
 * than quoted from the API.
 *
 * Distinct from NotPermittedException, which means the token is real and may not do this.
 */
final class NotAuthenticatedException extends ApiException
{
}
