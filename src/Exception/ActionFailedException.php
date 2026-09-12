<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Entity\Action;

/**
 * The action finished and it did not work - status `errored`.
 *
 * WHERE THE EXPLANATION IS, AND IS NOT. `reason` is narration - it describes what was being
 * attempted, in the same words whether the action worked or not. An errored uptime action was
 * measured carrying "Your server uptime is being checked", which explains nothing. The field
 * that could explain is `error_message`, which the specification does not declare on an action
 * and the API returns anyway; `Action::failureReason()` reads it.
 *
 * So this message quotes `error_message` when there is one, says plainly that there was none
 * when there is not, and offers `reason` as narration rather than as a cause. There is no
 * error code either way.
 */
final class ActionFailedException extends ActionException
{
    public static function for(Action $action): self
    {
        $what = $action->type !== '' ? $action->type : $action->title;
        $explanation = $action->failureReason();

        if ($explanation !== null) {
            $message = sprintf('The BinaryLane action #%d (%s) failed: %s', $action->id, $what, $explanation);
        } elseif (trim($action->reason) !== '') {
            $message = sprintf(
                'The BinaryLane action #%d (%s) failed, and BinaryLane gave no reason for it. Its '
                    . 'last progress note was "%s", which describes what was being attempted rather '
                    . 'than what went wrong.',
                $action->id,
                $what,
                trim($action->reason)
            );
        } else {
            $message = sprintf('The BinaryLane action #%d (%s) failed, with no explanation.', $action->id, $what);
        }

        return new self($message, $action);
    }
}
