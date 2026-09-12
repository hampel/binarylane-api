<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What offsite copies cost, per GB per month, broken down by which schedule is being copied.
 *
 * ONLY ONE OF THESE APPLIES AT A TIME, and it is the most frequent schedule enabled - the
 * specification notes each is "only charged if" that is the highest frequency in use.
 * forMostFrequent() picks it, so a cost estimate does not add three numbers that were never
 * meant to be added.
 */
final class OffsiteBackupFrequencyCost implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly float $dailyPerGigabyte = 0.0,
        public readonly float $weeklyPerGigabyte = 0.0,
        public readonly float $monthlyPerGigabyte = 0.0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::float($row['daily_per_gigabyte'] ?? null) ?? 0.0,
            Cast::float($row['weekly_per_gigabyte'] ?? null) ?? 0.0,
            Cast::float($row['monthly_per_gigabyte'] ?? null) ?? 0.0,
            $row,
        );
    }

    public function for(BackupSlot $slot): float
    {
        return match ($slot) {
            BackupSlot::Daily => $this->dailyPerGigabyte,
            BackupSlot::Weekly => $this->weeklyPerGigabyte,
            BackupSlot::Monthly => $this->monthlyPerGigabyte,
            BackupSlot::Temporary => 0.0,
        };
    }

    /**
     * The rate that will actually be charged, given which schedules are enabled.
     *
     * Daily beats weekly beats monthly, because the charge follows the most frequent copy
     * rather than summing them.
     */
    public function forMostFrequent(bool $daily, bool $weekly, bool $monthly): float
    {
        return match (true) {
            $daily => $this->dailyPerGigabyte,
            $weekly => $this->weeklyPerGigabyte,
            $monthly => $this->monthlyPerGigabyte,
            default => 0.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'daily_per_gigabyte' => $this->dailyPerGigabyte,
            'weekly_per_gigabyte' => $this->weeklyPerGigabyte,
            'monthly_per_gigabyte' => $this->monthlyPerGigabyte,
        ];
    }
}
