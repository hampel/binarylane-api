<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * Rate limited - HTTP 429.
 *
 * NOT IN THE SPECIFICATION. BinaryLane documents no rate limit and declares no 429 on any
 * operation, so this type exists for the case where one appears anyway - an edge in front of
 * the API, a limit added later - rather than because the API is known to use it. It is
 * mapped so that such a response arrives as something specific and retryable instead of
 * falling through to ClientException.
 *
 * `retryAfter` carries the header when the response sent a plain integer.
 */
final class TooManyRequestsException extends ApiException
{
}
