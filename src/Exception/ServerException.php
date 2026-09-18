<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * BinaryLane failed - HTTP 5xx. Nothing the caller sent is necessarily wrong, and a retried
 * read is reasonable in a way it is not for any of the others.
 *
 * A 502, 503 OR 504 MAY NOT BE BINARYLANE'S ANSWER AT ALL. It can come from the gateway in
 * front of the API, which gives up at 60 seconds while the request carries on behind it - a
 * zone creation measured at about a minute answers 504 and is created anyway. So after a 5xx
 * the outcome of a write is as unknown as after RequestException, and the same rule applies:
 * find out before repeating it. Re-read the resource, or for a mutation that answers with an
 * Action, look in the action list - especially before repeating a resize, a rebuild or a
 * restore.
 */
final class ServerException extends ApiException
{
}
