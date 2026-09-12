<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * An action did not reach `completed`.
 *
 * Distinct from ApiException: every request succeeded, and what failed is the WORK the API
 * agreed to do. A rebuild that errors comes back as a 200 carrying an errored action, so
 * nothing in the HTTP layer is in a position to notice.
 *
 * The action itself is attached, because it is where the answer is - `reason` for a failure,
 * `userInteractionRequired` for a question, `blockingInvoiceId` for an unpaid invoice.
 */
abstract class ActionException extends BinaryLaneException
{
    final public function __construct(
        string $message,
        public readonly Action $action,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
