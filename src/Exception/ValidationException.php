<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * BinaryLane rejected a value in the request - HTTP 400.
 *
 * The commonest failure against this API and the one worth catching by name, because it is
 * the only one a caller can usually fix from what came back: it is the single status that
 * answers with `errors`, a map of JSON property name to a list of messages. See
 * ApiException::fieldErrors().
 *
 * 400 covers more than a malformed value. A size that does not exist in the chosen region,
 * a hostname already in use, a server action that cannot run in the server's current state
 * and an image that is not licensed for the account all arrive here rather than as a
 * conflict or a 422.
 */
final class ValidationException extends ApiException
{
}
