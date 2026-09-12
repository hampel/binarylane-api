<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A server plan: what it includes, where it is offered, and what it costs.
 *
 * `available` AND `regions` AND `regionsOutOfStock` ARE THREE DIFFERENT ANSWERS and a create
 * request needs all three to succeed:
 *
 *  - `available` false means the size takes no new servers anywhere. Existing ones keep
 *    running; this is how a retired plan is reported.
 *  - `regions` is where the size is offered AT ALL.
 *  - `regionsOutOfStock` is where it is normally offered and cannot be had today.
 *
 * isAvailableIn() is all three together, which is the question actually being asked.
 *
 * THIS SIZE MAY NOT ACCEPT THE IMAGE YOU HAVE IN MIND, and nothing on the object says so. A
 * size list requested with an image selected omits the sizes that image cannot be installed
 * on, and narrows the `regions` of those that remain to where it is available; asked without
 * one, you get the whole catalogue. So a size taken from an unfiltered list is not a promise -
 * see Endpoint\Sizes::forImage(), which is what a create form should be built from.
 *
 * THE UNITS ARE BINARYLANE'S: memory in MB (1024² bytes), disk in GB (1024³), transfer in TB
 * where a TB is 1000 of those GB. Prices are AU$.
 */
final class Size implements \JsonSerializable
{
    /**
     * @param  list<string>  $regions  where this size is offered at all
     * @param  list<string>|null  $regionsOutOfStock  where it is offered and currently is not
     *                                                available. Null when the API did not say
     * @param  int  $memory  MB
     * @param  int  $disk  GB
     * @param  float  $transfer  TB per month
     * @param  string  $vcpuUnits  what `vcpus` counts - "core" or "thread". The difference is
     *                             the whole meaning of the number beside it
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $slug,
        public readonly ?SizeType $sizeType = null,
        public readonly bool $available = false,
        public readonly array $regions = [],
        public readonly ?array $regionsOutOfStock = null,
        public readonly float $priceMonthly = 0.0,
        public readonly float $priceHourly = 0.0,
        public readonly int $disk = 0,
        public readonly int $memory = 0,
        public readonly float $transfer = 0.0,
        public readonly float $excessTransferCostPerGigabyte = 0.0,
        public readonly int $vcpus = 0,
        public readonly string $vcpuUnits = '',
        public readonly ?SizeOptions $options = null,
        public readonly ?string $description = null,
        public readonly ?string $cpuDescription = null,
        public readonly ?string $storageDescription = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['slug'] ?? null) ?? '',
            Cast::nested($row['size_type'] ?? null, SizeType::fromArray(...)),
            Cast::bool($row['available'] ?? null) ?? false,
            Cast::strings($row['regions'] ?? null),
            is_array($row['regions_out_of_stock'] ?? null) ? Cast::strings($row['regions_out_of_stock']) : null,
            Cast::float($row['price_monthly'] ?? null) ?? 0.0,
            Cast::float($row['price_hourly'] ?? null) ?? 0.0,
            Cast::int($row['disk'] ?? null) ?? 0,
            Cast::int($row['memory'] ?? null) ?? 0,
            Cast::float($row['transfer'] ?? null) ?? 0.0,
            Cast::float($row['excess_transfer_cost_per_gigabyte'] ?? null) ?? 0.0,
            Cast::int($row['vcpus'] ?? null) ?? 0,
            Cast::string($row['vcpu_units'] ?? null) ?? '',
            Cast::nested($row['options'] ?? null, SizeOptions::fromArray(...)),
            Cast::string($row['description'] ?? null),
            Cast::string($row['cpu_description'] ?? null),
            Cast::string($row['storage_description'] ?? null),
            $row,
        );
    }

    /**
     * Whether a server of this size can be created in this region right now.
     *
     * All three conditions, because they fail independently and each produces the same 400.
     */
    public function isAvailableIn(string $region): bool
    {
        return $this->available
            && in_array($region, $this->regions, true)
            && !$this->isOutOfStockIn($region);
    }

    /**
     * Whether the size is offered here and temporarily unavailable - as distinct from not
     * being offered here at all, which is a permanent answer.
     */
    public function isOutOfStockIn(string $region): bool
    {
        return in_array($region, $this->regionsOutOfStock ?? [], true);
    }

    /**
     * Whether the size is offered in this region, stock aside.
     */
    public function isOfferedIn(string $region): bool
    {
        return in_array($region, $this->regions, true);
    }

    /**
     * The regions a server of this size could be created in today.
     *
     * @return list<string>
     */
    public function availableRegions(): array
    {
        if (!$this->available) {
            return [];
        }

        return array_values(array_filter(
            $this->regions,
            fn (string $region): bool => !$this->isOutOfStockIn($region)
        ));
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
     * The included transfer in GB. A TB here is 1000 GB - see the class note.
     */
    public function transferGigabytes(): float
    {
        return $this->transfer * 1000;
    }

    /**
     * What going over the included transfer by this many GB would cost, in AU$.
     */
    public function excessTransferCost(float $gigabytes): float
    {
        return max(0.0, $gigabytes) * $this->excessTransferCostPerGigabyte;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'slug' => $this->slug,
            'size_type' => $this->sizeType,
            'available' => $this->available,
            'regions' => $this->regions,
            'regions_out_of_stock' => $this->regionsOutOfStock,
            'price_monthly' => $this->priceMonthly,
            'price_hourly' => $this->priceHourly,
            'disk' => $this->disk,
            'memory' => $this->memory,
            'transfer' => $this->transfer,
            'excess_transfer_cost_per_gigabyte' => $this->excessTransferCostPerGigabyte,
            'vcpus' => $this->vcpus,
            'vcpu_units' => $this->vcpuUnits,
            'options' => $this->options,
            'description' => $this->description,
            'cpu_description' => $this->cpuDescription,
            'storage_description' => $this->storageDescription,
        ];
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
