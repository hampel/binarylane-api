<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Where an action has got to.
 *
 * ONLY ONE OF THESE MEANS SUCCESS. `completed` does; `errored` does not; `in-progress` is
 * not an answer yet. A caller that treats "not in progress" as "worked" will report a failed
 * rebuild as a finished one, which is why isFinished() and isSuccessful() are separate
 * questions here rather than one.
 */
enum ActionStatus: string
{
    /** Still running. `completed_at` is null while this is the status. */
    case InProgress = 'in-progress';

    /** Finished, and it worked. */
    case Completed = 'completed';

    /** Finished, and it did not. The `reason` on the action is where to look. */
    case Errored = 'errored';

    /**
     * Whether the API is still working on it.
     */
    public function isRunning(): bool
    {
        return $this === self::InProgress;
    }

    /**
     * Whether it has stopped, either way. The question a polling loop asks.
     */
    public function isFinished(): bool
    {
        return $this !== self::InProgress;
    }

    /**
     * Whether it stopped successfully. The question the caller asks afterwards.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    public function hasFailed(): bool
    {
        return $this === self::Errored;
    }
}
