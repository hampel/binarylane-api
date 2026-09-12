<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What a size can be adjusted to, and what each adjustment costs.
 *
 * EVERY COST IS AU$ PER MONTH, PRO-RATED, and every one of them is ADDITIONAL to the size's
 * own price - there is no total in here. A server's bill is the size's `priceMonthly` plus
 * whatever these multiply out to for the options actually selected.
 *
 * `restrictedDiskValues` IS THE FIELD THAT CATCHES PEOPLE. When it is null, any value between
 * `diskMin` and `diskMax` is allowed. When it is NOT null, only the values it lists are - the
 * range is still true and no longer sufficient, so a disk size validated against the range
 * alone is rejected with a 400. allowsDisk() applies both rules.
 *
 * `discountForNoPublicIpv4` is a saving rather than a charge: a server created with no public
 * IPv4 address costs that much less per month.
 */
final class SizeOptions implements \JsonSerializable
{
    /**
     * @param  list<int>|null  $restrictedDiskValues  null means "the whole range"; a list
     *                                                means "only these"
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $diskMin = 0,
        public readonly int $diskMax = 0,
        public readonly float $diskCostPerAdditionalGigabyte = 0.0,
        public readonly ?array $restrictedDiskValues = null,
        public readonly int $memoryMax = 0,
        public readonly float $memoryCostPerAdditionalMegabyte = 0.0,
        public readonly float $transferMax = 0.0,
        public readonly float $transferCostPerAdditionalGigabyte = 0.0,
        public readonly int $ipv4AddressesMax = 0,
        public readonly float $ipv4AddressesCostPerAddress = 0.0,
        public readonly float $discountForNoPublicIpv4 = 0.0,
        public readonly int $dailyBackups = 0,
        public readonly int $weeklyBackups = 0,
        public readonly int $monthlyBackups = 0,
        public readonly float $backupsCostPerBackupPerGigabyte = 0.0,
        public readonly float $offsiteBackupsCostPerGigabyte = 0.0,
        public readonly ?OffsiteBackupFrequencyCost $offsiteBackupFrequencyCost = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        // Null and absent both mean "no restriction"; an empty list would mean "no value is
        // allowed", which is not something the API sends, so they are kept apart here.
        $restricted = array_key_exists('restricted_disk_values', $row) && is_array($row['restricted_disk_values'])
            ? Cast::ints($row['restricted_disk_values'])
            : null;

        return new self(
            Cast::int($row['disk_min'] ?? null) ?? 0,
            Cast::int($row['disk_max'] ?? null) ?? 0,
            Cast::float($row['disk_cost_per_additional_gigabyte'] ?? null) ?? 0.0,
            $restricted,
            Cast::int($row['memory_max'] ?? null) ?? 0,
            Cast::float($row['memory_cost_per_additional_megabyte'] ?? null) ?? 0.0,
            Cast::float($row['transfer_max'] ?? null) ?? 0.0,
            Cast::float($row['transfer_cost_per_additional_gigabyte'] ?? null) ?? 0.0,
            Cast::int($row['ipv4_addresses_max'] ?? null) ?? 0,
            Cast::float($row['ipv4_addresses_cost_per_address'] ?? null) ?? 0.0,
            Cast::float($row['discount_for_no_public_ipv4'] ?? null) ?? 0.0,
            Cast::int($row['daily_backups'] ?? null) ?? 0,
            Cast::int($row['weekly_backups'] ?? null) ?? 0,
            Cast::int($row['monthly_backups'] ?? null) ?? 0,
            Cast::float($row['backups_cost_per_backup_per_gigabyte'] ?? null) ?? 0.0,
            Cast::float($row['offsite_backups_cost_per_gigabyte'] ?? null) ?? 0.0,
            Cast::nested($row['offsite_backup_frequency_cost'] ?? null, OffsiteBackupFrequencyCost::fromArray(...)),
            $row,
        );
    }

    /**
     * Whether a disk size will be accepted, applying both rules.
     *
     * The range alone is not enough when `restrictedDiskValues` is present - which is the
     * whole reason this method exists rather than a comparison at the call site.
     */
    public function allowsDisk(int $gigabytes): bool
    {
        if ($gigabytes < $this->diskMin || $gigabytes > $this->diskMax) {
            return false;
        }

        return $this->restrictedDiskValues === null
            || in_array($gigabytes, $this->restrictedDiskValues, true);
    }

    /**
     * Whether this size only accepts particular disk sizes rather than a range.
     */
    public function hasRestrictedDiskValues(): bool
    {
        return $this->restrictedDiskValues !== null;
    }

    public function allowsMemory(int $megabytes): bool
    {
        return $megabytes > 0 && $megabytes <= $this->memoryMax;
    }

    public function allowsIpv4Addresses(int $count): bool
    {
        return $count >= 0 && $count <= $this->ipv4AddressesMax;
    }

    /**
     * Whether the size can be given more of something than it comes with, at all.
     *
     * A max equal to the size's own included amount means the option exists and cannot be
     * increased - the specification calls that out for transfer specifically.
     */
    public function isUpgradeable(Size $size): bool
    {
        return $this->diskMax > $size->disk
            || $this->memoryMax > $size->memory
            || $this->transferMax > $size->transfer
            || $this->ipv4AddressesMax > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'disk_min' => $this->diskMin,
            'disk_max' => $this->diskMax,
            'disk_cost_per_additional_gigabyte' => $this->diskCostPerAdditionalGigabyte,
            'restricted_disk_values' => $this->restrictedDiskValues,
            'memory_max' => $this->memoryMax,
            'memory_cost_per_additional_megabyte' => $this->memoryCostPerAdditionalMegabyte,
            'transfer_max' => $this->transferMax,
            'transfer_cost_per_additional_gigabyte' => $this->transferCostPerAdditionalGigabyte,
            'ipv4_addresses_max' => $this->ipv4AddressesMax,
            'ipv4_addresses_cost_per_address' => $this->ipv4AddressesCostPerAddress,
            'discount_for_no_public_ipv4' => $this->discountForNoPublicIpv4,
            'daily_backups' => $this->dailyBackups,
            'weekly_backups' => $this->weeklyBackups,
            'monthly_backups' => $this->monthlyBackups,
            'backups_cost_per_backup_per_gigabyte' => $this->backupsCostPerBackupPerGigabyte,
            'offsite_backups_cost_per_gigabyte' => $this->offsiteBackupsCostPerGigabyte,
            'offsite_backup_frequency_cost' => $this->offsiteBackupFrequencyCost,
        ];
    }
}
