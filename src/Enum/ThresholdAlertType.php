<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What a threshold alert measures.
 *
 * THE UNITS ARE NOT ALL PERCENTAGES, which matters because the alert value is a bare number.
 * `cpu`, `data-transfer-used`, `storage-used`, `memory-used` and `locked-backup-slots` are
 * percentages; `storage-requests`, `network-incoming` and `network-outgoing` are rates. Set
 * a network alert to 80 thinking in percent and it will fire constantly.
 *
 * `memory-used` CAN LEGITIMATELY EXCEED 100, because it counts swap against physical memory -
 * which is the point of the alert rather than a defect in it.
 */
enum ThresholdAlertType: string
{
    /** Average across all CPUs, as a percentage. 100 is the maximum however many processors there are. */
    case Cpu = 'cpu';

    /** Combined read and write requests to the storage subsystem. A rate, not a percentage. */
    case StorageRequests = 'storage-requests';

    /** Data arriving, from the internet and the LAN. A rate. */
    case NetworkIncoming = 'network-incoming';

    /** Data leaving, to the internet and the LAN. A rate. */
    case NetworkOutgoing = 'network-outgoing';

    /** Percentage of the monthly data transfer limit consumed. */
    case DataTransferUsed = 'data-transfer-used';

    /** Disk consumed as a percentage of total disk. */
    case StorageUsed = 'storage-used';

    /** Virtual memory as a percentage of physical memory. May exceed 100 - see the class note. */
    case MemoryUsed = 'memory-used';

    /** Percentage of scheduled backup slots held by locked backups. At 100, automated backups stop. */
    case LockedBackupSlots = 'locked-backup-slots';

    /**
     * Whether the alert's value is a percentage. The rest are rates.
     */
    public function isPercentage(): bool
    {
        return match ($this) {
            self::StorageRequests, self::NetworkIncoming, self::NetworkOutgoing => false,
            default => true,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Cpu => 'The average percentage of all CPU; 100% is the maximum possible even with multiple processors. A high average will prevent the server from responding quickly.',
            self::StorageRequests => 'The average number of requests (combined read and write) received by the storage subsystem. A high number often indicates swap usage due to memory exhaustion, and is associated with poor performance.',
            self::NetworkIncoming => 'The amount of data going into the server, from the internet and the LAN. A sudden increase may indicate a denial of service attack.',
            self::NetworkOutgoing => 'The amount of data coming out of the server, to the internet and the LAN. A sudden increase may indicate the server has been compromised and is being used for spam delivery.',
            self::DataTransferUsed => 'The percentage of the monthly data transfer limit consumed.',
            self::StorageUsed => 'Disk space consumed as a percentage of total disk space. Out of disk space, programs may fail to execute or be unable to create files, and the server may become unresponsive.',
            self::MemoryUsed => 'Virtual memory consumed as a percentage of physical memory. This includes swap, so it may exceed 100%, indicating the server has run out of physical memory.',
            self::LockedBackupSlots => 'The percentage of scheduled backup slots (daily, weekly, monthly) occupied by locked backups. When all slots are locked, automated backups cannot proceed.',
        };
    }
}
