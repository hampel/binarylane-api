<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A kind of load balancer that can be created, and what it costs.
 *
 * TWO KINDS, AND THE DIFFERENCE IS `anycast`. A regional load balancer lives in one region
 * and is created by naming it; an anycast one has no region and is created by naming none.
 * `regions` being null on an anycast option is the API saying "everywhere", not "nowhere".
 */
final class LoadBalancerAvailabilityOption implements \JsonSerializable
{
    /**
     * @param  list<string>|null  $regions  null on an anycast option, which is not regional
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $anycast = false,
        public readonly float $priceMonthly = 0.0,
        public readonly float $priceHourly = 0.0,
        public readonly ?array $regions = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::bool($row['anycast'] ?? null) ?? false,
            Cast::float($row['price_monthly'] ?? null) ?? 0.0,
            Cast::float($row['price_hourly'] ?? null) ?? 0.0,
            is_array($row['regions'] ?? null) ? Cast::strings($row['regions']) : null,
            $row,
        );
    }

    /**
     * Whether this option can be used in a region. Always true for an anycast option, which
     * is not regional.
     */
    public function isAvailableIn(string $region): bool
    {
        return $this->anycast || in_array(trim($region), $this->regions ?? [], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'anycast' => $this->anycast,
            'price_monthly' => $this->priceMonthly,
            'price_hourly' => $this->priceHourly,
            'regions' => $this->regions,
        ];
    }
}
