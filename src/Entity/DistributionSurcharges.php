<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What an operating system costs on top of the size, per month in AU$.
 *
 * PRESENT MEANS IT COSTS EXTRA. The field is null on an image with no surcharge, so this
 * object existing at all is the signal - a licensed operating system rather than a free one.
 *
 * The three components are added together and two of them are CAPPED FROM BELOW AND ABOVE in
 * ways the field names do not say: the per-megabyte charge applies only up to
 * `surchargePerMemoryMaxMegabytes`, and the per-vCPU charge applies to at least
 * `surchargeMinVcpu` vCPUs however few the size actually has. monthlyFor() applies both,
 * which is why a hand-rolled multiplication usually comes out low on a small server and high
 * on a large one.
 */
final class DistributionSurcharges implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?float $surchargeBaseCost = null,
        public readonly ?float $surchargePerMemoryMegabyte = null,
        public readonly ?int $surchargePerMemoryMaxMegabytes = null,
        public readonly ?float $surchargePerVcpu = null,
        public readonly ?int $surchargeMinVcpu = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::float($row['surcharge_base_cost'] ?? null),
            Cast::float($row['surcharge_per_memory_megabyte'] ?? null),
            Cast::int($row['surcharge_per_memory_max_megabytes'] ?? null),
            Cast::float($row['surcharge_per_vcpu'] ?? null),
            Cast::int($row['surcharge_min_vcpu'] ?? null),
            $row,
        );
    }

    /**
     * The monthly surcharge in AU$ for a server of this shape, with both caps applied.
     *
     * @param  int  $memoryMegabytes  the server's total memory - SelectedSizeOptions::$memory,
     *                                not the size's own, since extra memory is chargeable here
     *                                too
     */
    public function monthlyFor(int $memoryMegabytes, int $vcpus): float
    {
        $total = $this->surchargeBaseCost ?? 0.0;

        if ($this->surchargePerMemoryMegabyte !== null) {
            $chargeable = $this->surchargePerMemoryMaxMegabytes !== null
                ? min($memoryMegabytes, $this->surchargePerMemoryMaxMegabytes)
                : $memoryMegabytes;

            $total += $this->surchargePerMemoryMegabyte * max(0, $chargeable);
        }

        if ($this->surchargePerVcpu !== null) {
            $chargeable = max($vcpus, $this->surchargeMinVcpu ?? 0);

            $total += $this->surchargePerVcpu * max(0, $chargeable);
        }

        return $total;
    }

    /**
     * Whether anything here actually adds a charge.
     */
    public function isEmpty(): bool
    {
        return ($this->surchargeBaseCost ?? 0.0) === 0.0
            && ($this->surchargePerMemoryMegabyte ?? 0.0) === 0.0
            && ($this->surchargePerVcpu ?? 0.0) === 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'surcharge_base_cost' => $this->surchargeBaseCost,
            'surcharge_per_memory_megabyte' => $this->surchargePerMemoryMegabyte,
            'surcharge_per_memory_max_megabytes' => $this->surchargePerMemoryMaxMegabytes,
            'surcharge_per_vcpu' => $this->surchargePerVcpu,
            'surcharge_min_vcpu' => $this->surchargeMinVcpu,
        ];
    }
}
