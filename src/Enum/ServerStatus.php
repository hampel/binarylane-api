<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What state a server is in.
 *
 * BEWARE OF `off` IF YOU GENERATE ANYTHING FROM THE SPECIFICATION YOURSELF. The published
 * `openapi.yaml` lists this enum as `[new, active, archive, off]`, and a YAML 1.1 parser -
 * which is what PHP's ext-yaml, Symfony's parser and Python's PyYAML all are - reads a bare
 * `off` as the boolean false. Load the specification that way and this case silently becomes
 * `false` rather than `"off"`, producing a client that cannot recognise a powered-off server.
 * The wire value is the string `off`.
 *
 * `archive` AND `off` ARE BOTH POWERED OFF and they are not interchangeable: `off` is a
 * server you turned off and can turn back on, `archive` is one the account no longer pays
 * for. Only the first will answer a power-on.
 */
enum ServerStatus: string
{
    /** Being built. Not usable yet, and most actions will be refused. */
    case New = 'new';

    /** Running and usable. */
    case Active = 'active';

    /** Powered off because of cancellation or non-payment - see Uncancel. */
    case Archive = 'archive';

    /** Powered off, and can be powered back on. */
    case Off = 'off';

    /**
     * Whether the server is powered on.
     */
    public function isRunning(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the server is powered off, for either reason.
     */
    public function isOff(): bool
    {
        return $this === self::Off || $this === self::Archive;
    }

    /**
     * Whether powering it on is a thing that could work.
     *
     * False for `archive`, which needs the cancellation reverted first, and for `new`, which
     * is not built yet.
     */
    public function canPowerOn(): bool
    {
        return $this === self::Off;
    }
}
