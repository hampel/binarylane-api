<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * How - and whether - a server's password can be reset without the console.
 *
 * WHAT THIS COSTS IS A REBOOT, and which of these a server reports is the difference between
 * a password reset that interrupts service and one that does not. Check it before scripting
 * a reset against a production server rather than after.
 */
enum PasswordRecoveryType: string
{
    /** Not resettable through the API. Recovery console and rescue disk only. */
    case Manual = 'manual';

    /** The admin or root password can be cleared. A new one is set at the console. Requires a restart. */
    case OfflineClear = 'offline-clear';

    /** The password can be reset and new credentials sent. Requires a restart. */
    case OfflineChange = 'offline-change';

    /** The password can be reset with no reboot, through the QEMU guest agent. */
    case OnlineChange = 'online-change';

    /**
     * Whether resetting the password will restart the server.
     */
    public function requiresRestart(): bool
    {
        return $this === self::OfflineClear || $this === self::OfflineChange;
    }

    /**
     * Whether the API can reset the password at all.
     */
    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }

    public function description(): string
    {
        return match ($this) {
            self::Manual => 'Password must be reset manually using the recovery console and rescue disk.',
            self::OfflineClear => 'Password can be cleared for the admin/root user only. A new password is provided on login via the console. Requires a restart.',
            self::OfflineChange => 'Password can be reset and new credentials sent. Requires a restart.',
            self::OnlineChange => 'Password may be reset without a reboot, via the installed QEMU Guest Agent.',
        };
    }
}
