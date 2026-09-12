<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What an image is, when that image is a backup.
 *
 * THREE FLAGS DECIDE WHAT CAN BE DONE WITH IT, and two of them are refusals:
 *
 *  - `locked` - cannot be replaced. A locked backup holds its slot against the rotation, so
 *    enough of them stop scheduled backups happening at all (see
 *    ThresholdAlertType::LockedBackupSlots).
 *  - `iso` - an ISO image. CANNOT BE RESTORED OR DOWNLOADED, says the specification, so a
 *    restore plan built from a backup list has to filter these out.
 *  - `offsite` - an offsite copy was ATTEMPTED. Not that it succeeded; the field is
 *    documented in exactly those words.
 *
 * `minDiskSize()` across the disks is the number that decides whether a restore into a given
 * server will be accepted.
 */
final class BackupInfo implements \JsonSerializable
{
    /**
     * @param  list<BackupDisk>  $backupDisks
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?BackupSlot $type = null,
        public readonly int $serverId = 0,
        public readonly bool $offsite = false,
        public readonly bool $locked = false,
        public readonly bool $iso = false,
        public readonly array $backupDisks = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            BackupSlot::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            Cast::int($row['server_id'] ?? null) ?? 0,
            Cast::bool($row['offsite'] ?? null) ?? false,
            Cast::bool($row['locked'] ?? null) ?? false,
            Cast::bool($row['iso'] ?? null) ?? false,
            Cast::objects($row['backup_disks'] ?? null, BackupDisk::fromArray(...)),
            $row,
        );
    }

    /**
     * Whether this backup can be restored at all. False for an ISO image.
     */
    public function isRestorable(): bool
    {
        return !$this->iso;
    }

    /**
     * Whether the rotation may replace this backup. A locked one holds its slot.
     */
    public function isReplaceable(): bool
    {
        return !$this->locked;
    }

    /**
     * The disk a target server needs for every disk in this backup to fit.
     */
    public function minDiskSize(): int
    {
        $minimum = 0;

        foreach ($this->backupDisks as $disk) {
            $minimum = max($minimum, $disk->minDiskSize);
        }

        return $minimum;
    }

    /**
     * The total compressed size of the stored image, in GB - what it costs to keep, not what
     * it needs to restore.
     */
    public function storedGigabytes(): float
    {
        $total = 0.0;

        foreach ($this->backupDisks as $disk) {
            $total += $disk->sizeGigabytes;
        }

        return $total;
    }

    /**
     * Whether a server with this much disk could take this backup.
     */
    public function fitsIn(int $diskGigabytes): bool
    {
        return $this->isRestorable() && $diskGigabytes >= $this->minDiskSize();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'type' => $this->type?->value,
            'server_id' => $this->serverId,
            'offsite' => $this->offsite,
            'locked' => $this->locked,
            'iso' => $this->iso,
            'backup_disks' => $this->backupDisks,
        ];
    }
}
