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
 * about whether the operating system is up. The API's own design agrees: there is a dedicated
 * `is_running` SERVER ACTION, which would be redundant if this field answered the question.
 * `Endpoint\ServerActions::isRunning()` is how you actually find out, and like every action it
 * answers asynchronously.
 *
 * WHEN `off` APPEARS IS NOT KNOWN. The specification documents it as "the server has been
 * powered off, but may be powered back on", and it was not observed on a server that was in
 * exactly that condition. It may be reserved for a power-off performed through the API rather
 * than from inside the guest. Treat its ABSENCE as meaningless and its PRESENCE as reliable.
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

    /** Powered off because of cancellation or non-payment - see the Uncancel action. */
    case Archive = 'archive';

    /**
     * Powered off, and can be powered back on.
     *
     * Documented by the specification and not observed in practice on a server that was
     * genuinely off. Do not infer anything from its absence.
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
     * Whether the server has been taken out of service for cancellation or non-payment.
     *
     * The one status that genuinely means the server is stopped and will not answer a
     * power-on until the cancellation is reverted.
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
     * Ask ServerActions::isRunning() when the answer matters.
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
