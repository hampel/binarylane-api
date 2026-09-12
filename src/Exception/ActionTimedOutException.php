<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * The wait gave up. The action is still running.
 *
 * NOTHING HAS BEEN CANCELLED. This is a statement about how long this process was prepared
 * to wait, not about the work - the resize or the rebuild carries on, and asking again later
 * will find it finished. There is no way to cancel an action through the API.
 *
 * The action as last seen is attached, so `progress` says how far it had got.
 */
final class ActionTimedOutException extends ActionException
{
    public static function for(Action $action, int $seconds): self
    {
        return new self(
            sprintf(
                'Gave up waiting for the BinaryLane action #%d (%s) after %d seconds; it is '
                    . 'still running (%d%% complete) and has not been cancelled.',
                $action->id,
                $action->type !== '' ? $action->type : $action->title,
                $seconds,
                $action->progress->percentComplete ?? 0
            ),
            $action
        );
    }
}
