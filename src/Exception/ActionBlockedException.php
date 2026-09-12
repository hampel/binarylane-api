<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * The action stopped and will not resume on its own.
 *
 * IT IS STILL REPORTING `in-progress`, which is why this is raised rather than waited
 * through. Two things put an action here and both need somebody to do something outside the
 * API:
 *
 *  - it asked a question, and Actions::proceed() is the answer;
 *  - it is blocked by an unpaid invoice, named by `blockingInvoiceId`.
 *
 * Neither times out on BinaryLane's side, so a polling loop that did not raise here would
 * wait for as long as it was told to and then report a timeout, which is a true statement
 * about the wrong thing.
 */
final class ActionBlockedException extends ActionException
{
    public static function for(Action $action): self
    {
        if ($action->needsInteraction()) {
            return new self(
                sprintf(
                    'The BinaryLane action #%d (%s) is waiting on a response and will not '
                        . 'continue until it gets one. %s Answer it with Actions::proceed().',
                    $action->id,
                    $action->type !== '' ? $action->type : $action->title,
                    $action->userInteractionRequired?->question() ?? ''
                ),
                $action
            );
        }

        return new self(
            sprintf(
                'The BinaryLane action #%d (%s) is blocked by invoice %d, which has to be paid '
                    . 'before it will continue.',
                $action->id,
                $action->type !== '' ? $action->type : $action->title,
                (int) $action->blockingInvoiceId
            ),
            $action
        );
    }
}
