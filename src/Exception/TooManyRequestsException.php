<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * Rate limited - HTTP 429.
 *
 * DECLARED ON ONE OPERATION, Domains::refreshNameserverCache(): a refresh sent while another
 * is still in progress may be refused with a 429. BinaryLane documents no general rate limit,
 * so anywhere else this is the case where one appears anyway - an edge in front of the API, a
 * limit added later. It is mapped so that such a response arrives as something specific and
 * retryable instead of falling through to ClientException.
 *
 * `retryAfter` carries the header when the response sent a plain integer.
 */
final class TooManyRequestsException extends ApiException
{
}
