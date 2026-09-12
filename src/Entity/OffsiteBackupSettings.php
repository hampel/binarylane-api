<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Where offsite backup copies go, and who prunes them.
 *
 * `manageOffsiteCopies` ONLY MEANS ANYTHING WITH A CUSTOM LOCATION. The specification says
 * so directly: when BinaryLane's own offsite storage is used, the field has no effect. With a
 * custom location it decides whether BinaryLane deletes old copies there or leaves every one
 * it has ever written - which is the difference between a bucket that stays a fixed size and
 * one that grows without limit.
 */
final class OffsiteBackupSettings implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $useCustomBackupLocation = false,
        public readonly ?string $offsiteBackupLocation = null,
        public readonly ?bool $manageOffsiteCopies = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::bool($row['use_custom_backup_location'] ?? null) ?? false,
            Cast::string($row['offsite_backup_location'] ?? null),
            Cast::bool($row['manage_offsite_copies'] ?? null),
            $row,
        );
    }

    /**
     * Whether old copies at the custom location will be pruned by BinaryLane.
     *
     * False when there is no custom location, because the setting does not apply - which is
     * not the same as "copies accumulate".
     */
    public function prunesCustomLocation(): bool
    {
        return $this->useCustomBackupLocation && ($this->manageOffsiteCopies ?? false);
    }

    /**
     * The case worth alerting on: a custom location whose copies nothing is deleting.
     */
    public function accumulatesWithoutLimit(): bool
    {
        return $this->useCustomBackupLocation && !($this->manageOffsiteCopies ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'use_custom_backup_location' => $this->useCustomBackupLocation,
            'offsite_backup_location' => $this->offsiteBackupLocation,
            'manage_offsite_copies' => $this->manageOffsiteCopies,
        ];
    }
}
