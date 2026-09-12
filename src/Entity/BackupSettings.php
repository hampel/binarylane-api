<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * When a server's scheduled backups run.
 *
 * `backupHourOfDay` IS APPROXIMATE, per the specification - it schedules the window, not the
 * moment. See BackupWindow, which is the concrete "next one is expected between these two
 * times".
 *
 * SUNDAY IS 0 in `backupDayOfWeek`, matching PHP's `w` format rather than ISO-8601's
 * Monday-is-1. dayOfWeekName() spells it out so nothing has to remember which convention
 * this is.
 */
final class BackupSettings implements \JsonSerializable
{
    /**
     * @param  int  $backupDayOfWeek  0 is Sunday
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $backupHourOfDay = 0,
        public readonly int $backupDayOfWeek = 0,
        public readonly int $backupDayOfMonth = 1,
        public readonly ?OffsiteBackupSettings $offsiteBackupSettings = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['backup_hour_of_day'] ?? null) ?? 0,
            Cast::int($row['backup_day_of_week'] ?? null) ?? 0,
            Cast::int($row['backup_day_of_month'] ?? null) ?? 1,
            Cast::nested($row['offsite_backup_settings'] ?? null, OffsiteBackupSettings::fromArray(...)),
            $row,
        );
    }

    /**
     * The weekly backup's day, named - because 0 meaning Sunday is a convention worth not
     * having to look up.
     */
    public function dayOfWeekName(): string
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ][$this->backupDayOfWeek] ?? 'day ' . $this->backupDayOfWeek;
    }

    /**
     * Whether copies are written outside BinaryLane's own storage.
     */
    public function hasOffsiteCopies(): bool
    {
        return $this->offsiteBackupSettings !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'backup_hour_of_day' => $this->backupHourOfDay,
            'backup_day_of_week' => $this->backupDayOfWeek,
            'backup_day_of_month' => $this->backupDayOfMonth,
            'offsite_backup_settings' => $this->offsiteBackupSettings,
        ];
    }
}
