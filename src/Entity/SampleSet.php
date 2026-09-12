<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Performance and usage data for one server over one period.
 *
 * THE AVERAGES AND THE MAXIMUMS ARE NOT IN THE SAME PLACE. `average` is a whole Sample of
 * averaged values; the two maximums - memory and storage - are top-level fields with no
 * sample of their own. So there is no maximum CPU or maximum network rate, only the average,
 * and a spike between data points is invisible except in memory and storage.
 *
 * That is the thing worth knowing before using this for capacity work: a five-minute average
 * hides a thirty-second CPU pin entirely.
 */
final class SampleSet implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $serverId,
        public readonly ?Period $period = null,
        public readonly ?Sample $average = null,
        public readonly float $maximumMemoryMegabytes = 0.0,
        public readonly float $maximumStorageGigabytes = 0.0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['server_id'] ?? null) ?? 0,
            Cast::nested($row['period'] ?? null, Period::fromArray(...)),
            Cast::nested($row['average'] ?? null, Sample::fromArray(...)),
            Cast::float($row['maximum_memory_megabytes'] ?? null) ?? 0.0,
            Cast::float($row['maximum_storage_gigabytes'] ?? null) ?? 0.0,
            $row,
        );
    }

    /**
     * Peak memory as a percentage of the server's own memory - which is what
     * ThresholdAlertType::MemoryUsed measures, and which can exceed 100 because it counts swap.
     */
    public function peakMemoryPercentOf(int $serverMemoryMegabytes): ?float
    {
        return $serverMemoryMegabytes > 0
            ? $this->maximumMemoryMegabytes / $serverMemoryMegabytes * 100
            : null;
    }

    /**
     * Peak storage as a percentage of the server's own disk.
     */
    public function peakStoragePercentOf(int $serverDiskGigabytes): ?float
    {
        return $serverDiskGigabytes > 0
            ? $this->maximumStorageGigabytes / $serverDiskGigabytes * 100
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'server_id' => $this->serverId,
            'period' => $this->period,
            'average' => $this->average,
            'maximum_memory_megabytes' => $this->maximumMemoryMegabytes,
            'maximum_storage_gigabytes' => $this->maximumStorageGigabytes,
        ];
    }
}
