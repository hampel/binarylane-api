<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What state a server is in.
 *
 * THIS IS NOT A POWER STATE, AND THE NAMES MAKE IT LOOK LIKE ONE. Measured on 13 September
 * 2026: a server that was powered off reported `active`, as did all sixteen of its neighbours
 * that were running. No other field in the server payload carries a power state either.
 *
 * So `active` means "provisioned, paid for, in service" - a lifecycle state - and says nothing
 * about whether the operating system is up. `Endpoint\ServerActions::checkRunning()` is how
 * you actually find out, and like every action it answers asynchronously.
 *
 * `off` IS NOT CURRENTLY RETURNED. The specification has said so since 0.45.0, in as many
 * words: a powered-off server "is reported as `active`", and the `is_running` server action
 * is the way to ask. That confirms the measurement above rather than adding to it. The case
 * stays because the specification still lists the value, so a server reporting it would be
 * read rather than refused - and a server that DOES report `off` is off.
 *
 * BEWARE OF `off` IF YOU GENERATE ANYTHING FROM THE SPECIFICATION YOURSELF. The published
 * `openapi.yaml` lists this enum as `[new, active, archive, off]`, and a YAML 1.1 parser -
 * which is what PHP's ext-yaml, Symfony's parser and Python's PyYAML all are - reads a bare
 * `off` as the boolean false. The wire value is the string `off`.
 */
enum ServerStatus: string
{
    /** Being built. Not usable yet, and most actions will be refused. */
    case New = 'new';

    /** In service. NOT a statement that the server is powered on - see the class note. */
    case Active = 'active';

    /**
     * Powered off for non-payment.
     *
     * The specification described this as cancellation OR non-payment until it was corrected
     * to non-payment alone, at the same time as it began saying that a cancelled post-paid
     * server is not returned by the API at all. What status a cancelled PRE-PAID server
     * reports is now undocumented; `cancelled_at` is the field that answers it either way,
     * which is why Entity\Server::isCancelled() reads both.
     */
    case Archive = 'archive';

    /**
     * Powered off, and can be powered back on.
     *
     * Listed by the specification and, by its own account, not currently returned - a
     * powered-off server reports `active`. Do not infer anything from its absence.
     */
    case Off = 'off';

    /**
     * Whether the server is provisioned and paid for.
     *
     * THE HONEST READING OF `active`. It is not "running": a powered-off server reports this.
     */
    public function isInService(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the server is still being built.
     */
    public function isBuilding(): bool
    {
        return $this === self::New;
    }

    /**
     * Whether the server has been taken out of service.
     *
     * The one status that genuinely means the server is stopped and will not answer a
     * power-on. Entity\Server::isCancelled() is the broader question, because a cancellation
     * carries a date before it carries a status.
     */
    public function isArchived(): bool
    {
        return $this === self::Archive;
    }

    /**
     * Whether the API is explicitly reporting the server as powered off.
     *
     * TRUE IS RELIABLE; FALSE IS NOT. A server that is powered off may report `active`
     * instead - see the class note - so this answers "did the API say so", not "is it off".
     * Ask ServerActions::checkRunning() when the answer matters.
     */
    public function isExplicitlyPoweredOff(): bool
    {
        return $this === self::Off;
    }

    /**
     * Whether anything in this status stands in the way of a power-on.
     *
     * NOT "the server is off". A running server passes this too, because the status cannot
     * distinguish them - powering on a server that is already on is the API's problem to
     * refuse, and a cancelled or half-built one is the case worth catching here.
     */
    public function permitsPowerOn(): bool
    {
        return $this === self::Active || $this === self::Off;
    }
}
