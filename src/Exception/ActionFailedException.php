<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * The action finished and it did not work - status `errored`.
 *
 * `$e->action->reason` is BinaryLane's own explanation, and it is written for a person rather
 * than for a parser. There is no error code.
 */
final class ActionFailedException extends ActionException
{
    public static function for(Action $action): self
    {
        return new self(
            sprintf(
                'The BinaryLane action #%d (%s) failed%s',
                $action->id,
                $action->type !== '' ? $action->type : $action->title,
                $action->reason !== '' ? ': ' . $action->reason : '.'
            ),
            $action
        );
    }
}
