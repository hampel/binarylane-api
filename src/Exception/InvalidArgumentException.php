<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * The caller asked for something that cannot be sent - a page size above the 200 the API
 * accepts, a server action given a field that action does not have.
 *
 * Raised before a request is made, which is the point of it: a mistake caught here costs
 * nothing, and the same mistake caught by the API costs a round trip and comes back as a 400
 * whose message is about a JSON property name rather than about what you did.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
