<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What a particular server has actually been given, as opposed to what its size includes.
 *
 * READ THIS RATHER THAN THE SIZE when you want to know how much memory or disk a server has.
 * A BinaryLane size is a starting point with add-ons on top - extra memory, extra disk, extra
 * transfer, more IPv4 addresses, more retained backups - and this is the resolved total. The
 * Size is the catalogue entry; this is the order.
 *
 * THE UNITS ARE NOT UNIFORM, and they are BinaryLane's own: memory in MB (1024² bytes), disk
 * in GB (1024³), transfer in TB where a TB is 1000 GB of 1024³ bytes. The introduction to the
 * API spells that out; the field names do not.
 *
 * The backup counts are RETENTION, not frequency: `dailyBackups` of 2 means two daily backups
 * are kept, which is also how many daily slots exist - see BackupSlot.
 */
final class SelectedSizeOptions implements \JsonSerializable
{
    /**
     * @param  int  $memory  MB
     * @param  int  $disk  GB
     * @param  float  $transfer  TB per month
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $dailyBackups = 0,
        public readonly int $weeklyBackups = 0,
        public readonly int $monthlyBackups = 0,
        public readonly bool $offsiteBackups = false,
        public readonly int $ipv4Addresses = 0,
        public readonly int $memory = 0,
        public readonly int $disk = 0,
        public readonly float $transfer = 0.0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['daily_backups'] ?? null) ?? 0,
            Cast::int($row['weekly_backups'] ?? null) ?? 0,
            Cast::int($row['monthly_backups'] ?? null) ?? 0,
            Cast::bool($row['offsite_backups'] ?? null) ?? false,
            Cast::int($row['ipv4_addresses'] ?? null) ?? 0,
            Cast::int($row['memory'] ?? null) ?? 0,
            Cast::int($row['disk'] ?? null) ?? 0,
            Cast::float($row['transfer'] ?? null) ?? 0.0,
            $row,
        );
    }

    /**
     * How many scheduled backup slots this server has in total.
     *
     * Zero means backups are off, which is the condition worth alerting on.
     */
    public function backupSlots(): int
    {
        return $this->dailyBackups + $this->weeklyBackups + $this->monthlyBackups;
    }

    public function hasBackups(): bool
    {
        return $this->backupSlots() > 0;
    }

    /**
     * Whether the server has no public IPv4 address - which is a chargeable choice rather
     * than an accident, and attracts a discount. See SizeOptions::$discountForNoPublicIpv4.
     */
    public function hasNoPublicIpv4(): bool
    {
        return $this->ipv4Addresses === 0;
    }

    /**
     * Memory in bytes, with BinaryLane's binary megabyte.
     */
    public function memoryBytes(): int
    {
        return $this->memory * 1024 ** 2;
    }

    /**
     * Disk in bytes, with BinaryLane's binary gigabyte.
     */
    public function diskBytes(): int
    {
        return $this->disk * 1024 ** 3;
    }

    /**
     * The monthly transfer allowance in GB.
     *
     * A TB HERE IS 1000 GB, not 1024, and each of those GB is 1024³ bytes - BinaryLane's
     * introduction defines it that way explicitly.
     */
    public function transferGigabytes(): float
    {
        return $this->transfer * 1000;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'daily_backups' => $this->dailyBackups,
            'weekly_backups' => $this->weeklyBackups,
            'monthly_backups' => $this->monthlyBackups,
            'offsite_backups' => $this->offsiteBackups,
            'ipv4_addresses' => $this->ipv4Addresses,
            'memory' => $this->memory,
            'disk' => $this->disk,
            'transfer' => $this->transfer,
        ];
    }
}
