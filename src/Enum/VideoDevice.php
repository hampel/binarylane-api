<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The emulated graphics adapter presented to a server.
 */
enum VideoDevice: string
{
    case CirrusLogic = 'cirrus-logic';
    case Standard = 'standard';
    case Virtio = 'virtio';
    case VirtioWide = 'virtio-wide';

    public function description(): string
    {
        return match ($this) {
            self::CirrusLogic => 'Cirrus Logic GD5446',
            self::Standard => 'Standard VGA with VESA 2.0 extensions',
            self::Virtio => 'Virtio VGA (800x600)',
            self::VirtioWide => 'Virtio VGA (1600x900)',
        };
    }
}
