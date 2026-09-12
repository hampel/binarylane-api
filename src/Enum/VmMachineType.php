<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The QEMU machine type a server is emulated as.
 *
 * CHANGING THIS CHANGES THE VIRTUAL HARDWARE the guest sees, which some operating systems
 * notice and some licence checks care about. It is not a performance dial. Leave it alone
 * unless something specific requires an older chipset.
 *
 * The case names transliterate the wire values, `pc_i440fx_7point2point1` and all - so a
 * value copied out of BinaryLane's documentation can be found here without a translation
 * table.
 */
enum VmMachineType: string
{
    case PcI440fx1Point5 = 'pc_i440fx_1point5';
    case PcI440fx2Point11 = 'pc_i440fx_2point11';
    case PcI440fx4Point1 = 'pc_i440fx_4point1';
    case PcI440fx4Point2 = 'pc_i440fx_4point2';
    case PcI440fx5Point0 = 'pc_i440fx_5point0';
    case PcI440fx5Point1 = 'pc_i440fx_5point1';
    case PcI440fx7Point2 = 'pc_i440fx_7point2';
    case PcI440fx7Point2Point1 = 'pc_i440fx_7point2point1';
    case PcI440fx8Point2 = 'pc_i440fx_8point2';

    /**
     * The human-readable name BinaryLane shows - "PC i440FX 7.2.1".
     */
    public function description(): string
    {
        return 'PC i440FX ' . str_replace('point', '.', substr($this->value, strlen('pc_i440fx_')));
    }
}
