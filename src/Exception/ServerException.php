<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * BinaryLane failed - HTTP 5xx. Nothing the caller sent is necessarily wrong, and a retry is
 * reasonable in a way it is not for any of the others.
 *
 * A retried MUTATION is a different question from a retried read, and on this API it has a
 * real answer: nearly every mutation answers with an Action, and the action list for the
 * resource is what says whether the first attempt took effect. Check before repeating a
 * resize, a rebuild or a restore.
 */
final class ServerException extends ApiException
{
}
