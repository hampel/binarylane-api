<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * The add-ons asked for on a create or a resize - the specification's `SizeOptionsRequest`.
 *
 * EVERY VALUE IS ABSOLUTE, NOT A DELTA. `memory: 4096` means "have 4096 MB", not "add 4096
 * MB" - the specification says so field by field. A resize built by adding to the current
 * values doubles them.
 *
 * NULL IS A REAL ANSWER AND IT MEANS TWO DIFFERENT THINGS depending on the call. On a CREATE
 * it means "the default for this size"; on a RESIZE it means "keep what the server has". So
 * an option left unset is never "turn it off" - and the object only sends the fields it was
 * actually given, which is what makes that distinction survive.
 *
 *     SizeOptions::none()->withMemory(8192)->withDisk(160);
 *
 * The backup counts are RETENTION - how many are kept, which is also how many slots exist.
 * Setting `dailyBackups` to 0 turns daily backups off.
 */
final class SizeOptions implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $options  only the fields actually set
     */
    private function __construct(
        private readonly array $options = [],
    ) {
    }

    /**
     * Nothing set - every option left to its default, or to whatever the server has.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Built from what a server currently has, as the starting point for a change.
     *
     * USE THIS ONLY WHEN YOU MEAN TO PIN EVERY OPTION. It sends all eight fields, so anything
     * you do not then change is fixed at its current value rather than left alone - which is
     * the same outcome for a resize happening now, and a different one if the size's defaults
     * move underneath you. `none()` plus the one field you want is nearly always what a
     * change means.
     */
    public static function from(\Hampel\BinaryLane\Api\Entity\SelectedSizeOptions $selected): self
    {
        return new self([
            'daily_backups' => $selected->dailyBackups,
            'weekly_backups' => $selected->weeklyBackups,
            'monthly_backups' => $selected->monthlyBackups,
            'offsite_backups' => $selected->offsiteBackups,
            'ipv4_addresses' => $selected->ipv4Addresses,
            'memory' => $selected->memory,
            'disk' => $selected->disk,
            'transfer' => $selected->transfer,
        ]);
    }

    /**
     * Total memory in MB - an absolute value.
     */
    public function withMemory(int $megabytes): self
    {
        if ($megabytes < 1) {
            throw new InvalidArgumentException('Memory is a total in MB and must be positive.');
        }

        return $this->with('memory', $megabytes);
    }

    /**
     * Total storage in GB - an absolute value.
     *
     * A size may restrict this to particular values rather than a range; see
     * Entity\SizeOptions::allowsDisk(), and validateAgainst() below.
     */
    public function withDisk(int $gigabytes): self
    {
        if ($gigabytes < 1) {
            throw new InvalidArgumentException('Disk is a total in GB and must be positive.');
        }

        return $this->with('disk', $gigabytes);
    }

    /**
     * Total monthly transfer in TB - an absolute value. A TB here is 1000 GB.
     */
    public function withTransfer(float $terabytes): self
    {
        if ($terabytes < 0) {
            throw new InvalidArgumentException('Transfer is a total in TB and cannot be negative.');
        }

        return $this->with('transfer', $terabytes);
    }

    /**
     * How many public IPv4 addresses the server has in total.
     *
     * ZERO IS LEGAL AND DISCOUNTED - a server with no public IPv4 costs less per month (see
     * Entity\SizeOptions::$discountForNoPublicIpv4). It is also unreachable over IPv4, which
     * is the point of asking for it and the surprise if it was not.
     */
    public function withIpv4Addresses(int $count): self
    {
        if ($count < 0) {
            throw new InvalidArgumentException('An IPv4 address count cannot be negative.');
        }

        return $this->with('ipv4_addresses', $count);
    }

    /**
     * How many daily backups are retained. Zero turns them off.
     */
    public function withDailyBackups(int $count): self
    {
        return $this->withBackupCount('daily_backups', $count);
    }

    public function withWeeklyBackups(int $count): self
    {
        return $this->withBackupCount('weekly_backups', $count);
    }

    public function withMonthlyBackups(int $count): self
    {
        return $this->withBackupCount('monthly_backups', $count);
    }

    /**
     * Whether scheduled backups are duplicated offsite.
     */
    public function withOffsiteBackups(bool $enabled = true): self
    {
        return $this->with('offsite_backups', $enabled);
    }

    /**
     * Turn every scheduled backup off in one call.
     */
    public function withoutBackups(): self
    {
        return $this->withDailyBackups(0)->withWeeklyBackups(0)->withMonthlyBackups(0);
    }

    /**
     * Which addresses to give up, when reducing the IPv4 address count.
     *
     * REQUIRED WHEN REDUCING, AND OVER-SPECIFYING IS NOT HARMLESS. The specification says
     * that naming more addresses than the reduction actually removes causes the extras to be
     * "re-provisioned with new addresses" - so the server keeps its count and silently
     * changes which addresses it answers on. DNS, firewall rules and allowlists elsewhere all
     * point at the old ones.
     *
     * Resize only; the create request has no such field.
     *
     * @param  list<string>  $addresses
     */
    public function withIpv4AddressesToRemove(array $addresses): self
    {
        return $this->with('ipv4_addresses_to_remove', array_values($addresses));
    }

    /**
     * Check the values against BinaryLane's granularity rules.
     *
     * THESE ARE NOT ROUND NUMBERS FOR THE SAKE OF IT and they are easy to violate by
     * arithmetic - doubling 2560 MB, or adding 25 GB to a 60 GB disk. The specification states
     * them on the resize request, field by field:
     *
     *   memory   multiples of 128; above 2048 MB of 1024; above 16384 of 2048; above 24576 of 4096
     *   disk     multiples of 5; above 60 GB of 10; above 200 GB of 100
     *   transfer in GB (the TB value times 1000): multiples of 5; above 30 of 10; above 200 of
     *            100; above 2000 of 1000
     *
     * The disk rules apply only to a size that does not restrict disk to particular values;
     * where it does, Entity\SizeOptions::allowsDisk() is the rule instead and validateAgainst()
     * applies it.
     *
     * Called by validateAgainst(). Separate so it can be used without a fetched Size, and so
     * it can be skipped: the specification documents these for the resize request, and a
     * create asking for something between the steps is refused here rather than by the API.
     */
    public function validateGranularity(): self
    {
        $memory = $this->options['memory'] ?? null;

        if (is_int($memory)) {
            $step = match (true) {
                $memory > 24576 => 4096,
                $memory > 16384 => 2048,
                $memory > 2048 => 1024,
                default => 128,
            };

            if ($memory % $step !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'BinaryLane accepts memory above %s in multiples of %d MB; %d MB is not one. '
                        . 'The nearest acceptable values are %d and %d.',
                    match ($step) {
                        4096 => '24576 MB',
                        2048 => '16384 MB',
                        1024 => '2048 MB',
                        default => '0 MB',
                    },
                    $step,
                    $memory,
                    intdiv($memory, $step) * $step,
                    (intdiv($memory, $step) + 1) * $step
                ));
            }
        }

        $disk = $this->options['disk'] ?? null;

        if (is_int($disk)) {
            $step = match (true) {
                $disk > 200 => 100,
                $disk > 60 => 10,
                default => 5,
            };

            if ($disk % $step !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'BinaryLane accepts disk above %d GB in multiples of %d GB; %d GB is not one. '
                        . 'The nearest acceptable values are %d and %d.',
                    match ($step) {
                        100 => 200,
                        10 => 60,
                        default => 0,
                    },
                    $step,
                    $disk,
                    intdiv($disk, $step) * $step,
                    (intdiv($disk, $step) + 1) * $step
                ));
            }
        }

        $transfer = $this->options['transfer'] ?? null;

        if (is_int($transfer) || is_float($transfer)) {
            // The rules are written in GB, and the field is in TB - which is the whole trap:
            // 0.5 TB is 500 GB and legal, 0.503 TB is 503 GB and is not.
            $gigabytes = (float) $transfer * 1000;

            if (abs($gigabytes - round($gigabytes)) > 0.0001) {
                throw new InvalidArgumentException(sprintf(
                    'Transfer is accepted in whole GB; %s TB is %s GB.',
                    (string) $transfer,
                    (string) $gigabytes
                ));
            }

            $gigabytes = (int) round($gigabytes);

            $step = match (true) {
                $gigabytes > 2000 => 1000,
                $gigabytes > 200 => 100,
                $gigabytes > 30 => 10,
                default => 5,
            };

            if ($gigabytes % $step !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'BinaryLane accepts transfer above %d GB in multiples of %d GB; %s TB is %d GB, '
                        . 'which is not one.',
                    match ($step) {
                        1000 => 2000,
                        100 => 200,
                        10 => 30,
                        default => 0,
                    },
                    $step,
                    (string) $transfer,
                    $gigabytes
                ));
            }
        }

        return $this;
    }

    /**
     * Check what has been asked for against what a size permits, before spending a request.
     *
     * Raises on the first violation with a message naming the rule, rather than the 400's
     * message naming the field. Only checks the fields that were actually set.
     */
    public function validateAgainst(Size $size): self
    {
        $this->validateGranularity();

        $limits = $size->options;

        if ($limits === null) {
            return $this;
        }

        if (isset($this->options['disk']) && is_int($this->options['disk']) && !$limits->allowsDisk($this->options['disk'])) {
            throw new InvalidArgumentException(sprintf(
                'The size "%s" %s; %d GB was asked for.',
                $size->slug,
                $limits->hasRestrictedDiskValues()
                    ? 'only accepts these disk sizes: ' . implode(', ', $limits->restrictedDiskValues ?? [])
                    : sprintf('accepts a disk between %d and %d GB', $limits->diskMin, $limits->diskMax),
                $this->options['disk']
            ));
        }

        if (isset($this->options['memory']) && is_int($this->options['memory']) && !$limits->allowsMemory($this->options['memory'])) {
            throw new InvalidArgumentException(sprintf(
                'The size "%s" accepts up to %d MB of memory; %d MB was asked for.',
                $size->slug,
                $limits->memoryMax,
                $this->options['memory']
            ));
        }

        if (isset($this->options['ipv4_addresses']) && is_int($this->options['ipv4_addresses'])
            && !$limits->allowsIpv4Addresses($this->options['ipv4_addresses'])) {
            throw new InvalidArgumentException(sprintf(
                'The size "%s" accepts up to %d IPv4 addresses; %d were asked for.',
                $size->slug,
                $limits->ipv4AddressesMax,
                $this->options['ipv4_addresses']
            ));
        }

        if (isset($this->options['transfer']) && is_numeric($this->options['transfer'])
            && (float) $this->options['transfer'] > $limits->transferMax) {
            throw new InvalidArgumentException(sprintf(
                'The size "%s" accepts up to %s TB of transfer; %s TB was asked for.',
                $size->slug,
                (string) $limits->transferMax,
                (string) $this->options['transfer']
            ));
        }

        return $this;
    }

    /**
     * Whether anything has been set at all. An empty options object is not sent.
     */
    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->options;
    }

    /**
     * The retention caps the specification states on the resize request: 14 daily, 13 weekly,
     * 12 monthly. They are not the same number, which is the reason to check rather than
     * assume.
     */
    private function withBackupCount(string $key, int $count): self
    {
        $maximum = ['daily_backups' => 14, 'weekly_backups' => 13, 'monthly_backups' => 12][$key] ?? 14;

        if ($count < 0) {
            throw new InvalidArgumentException('A retained backup count cannot be negative; 0 turns them off.');
        }

        if ($count > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'BinaryLane retains at most %d %s backups; %d were asked for.',
                $maximum,
                str_replace('_backups', '', $key),
                $count
            ));
        }

        return $this->with($key, $count);
    }

    private function with(string $key, mixed $value): self
    {
        return new self([...$this->options, $key => $value]);
    }
}
