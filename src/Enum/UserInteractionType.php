<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The question an action is waiting on an answer to.
 *
 * AN ACTION CAN STOP AND ASK. When it does it stays `in-progress` and carries a
 * `user_interaction_required`; nothing moves until Actions::proceed() answers it. A polling
 * loop that only watches the status will wait forever, which is why Actions::await() checks
 * for this and raises rather than spinning.
 */
enum UserInteractionType: string
{
    /** The server was created but did not answer a ping. Continue as though it had succeeded? */
    case ContinueAfterPingFailure = 'continue-after-ping-failure';

    /** A clean shutdown failed. May we power it off uncleanly? */
    case AllowUncleanPowerOff = 'allow-unclean-power-off';

    /**
     * The question, phrased as a question.
     */
    public function question(): string
    {
        return match ($this) {
            self::ContinueAfterPingFailure => 'Should we assume the server creation was successful despite failing to ping the server?',
            self::AllowUncleanPowerOff => 'Are we permitted to perform an unclean power off after the server failed to perform a clean shutdown?',
        };
    }
}
