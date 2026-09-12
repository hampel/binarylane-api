<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One set of performance measurements.
 *
 * THE UNITS ARE MIXED AND THE FIELD NAMES CARRY THEM - bytes for memory, MB for storage, KB
 * per second for the four rates. A KB here is 1024 bytes, per the specification; the accessors
 * below convert without having to remember which.
 *
 * `cpuUsagePercent` IS CAPPED AT 100 HOWEVER MANY PROCESSORS THERE ARE - it is the average
 * across all of them, not a sum. `cpuUsageDetailed` is the per-vCPU breakdown, and that one
 * does go to 100 per core.
 */
final class Sample implements \JsonSerializable
{
    /**
     * @param  list<float>  $cpuUsageDetailed  per virtual CPU
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly float $cpuUsagePercent = 0.0,
        public readonly array $cpuUsageDetailed = [],
        public readonly float $memoryUsageBytes = 0.0,
        public readonly float $networkIncomingKbps = 0.0,
        public readonly float $networkOutgoingKbps = 0.0,
        public readonly float $storageUsageMegabytes = 0.0,
        public readonly float $storageReadKbps = 0.0,
        public readonly float $storageWriteKbps = 0.0,
        public readonly float $storageReadRequestsPerSecond = 0.0,
        public readonly float $storageWriteRequestsPerSecond = 0.0,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $detailed = [];

        foreach (Cast::array($row['cpu_usage_detailed'] ?? null) as $value) {
            $percent = Cast::float($value);

            if ($percent !== null) {
                $detailed[] = $percent;
            }
        }

        return new self(
            Cast::float($row['cpu_usage_percent'] ?? null) ?? 0.0,
            $detailed,
            Cast::float($row['memory_usage_bytes'] ?? null) ?? 0.0,
            Cast::float($row['network_incoming_kbps'] ?? null) ?? 0.0,
            Cast::float($row['network_outgoing_kbps'] ?? null) ?? 0.0,
            Cast::float($row['storage_usage_megabytes'] ?? null) ?? 0.0,
            Cast::float($row['storage_read_kbps'] ?? null) ?? 0.0,
            Cast::float($row['storage_write_kbps'] ?? null) ?? 0.0,
            Cast::float($row['storage_read_requests_per_second'] ?? null) ?? 0.0,
            Cast::float($row['storage_write_requests_per_second'] ?? null) ?? 0.0,
            $row,
        );
    }

    /**
     * Memory used, in MB - the unit the threshold alerts and the size options use.
     */
    public function memoryMegabytes(): float
    {
        return $this->memoryUsageBytes / 1024 ** 2;
    }

    /**
     * Storage used, in GB.
     */
    public function storageGigabytes(): float
    {
        return $this->storageUsageMegabytes / 1024;
    }

    /**
     * Incoming network rate in megabits per second - what a network graph is usually labelled
     * in, and three conversions away from what the API reports.
     *
     * KB here is 1024 bytes, and a bit is an eighth of a byte.
     */
    public function networkIncomingMbps(): float
    {
        return $this->networkIncomingKbps * 1024 * 8 / 1_000_000;
    }

    public function networkOutgoingMbps(): float
    {
        return $this->networkOutgoingKbps * 1024 * 8 / 1_000_000;
    }

    /**
     * Combined storage requests per second - what ThresholdAlertType::StorageRequests measures.
     */
    public function storageRequestsPerSecond(): float
    {
        return $this->storageReadRequestsPerSecond + $this->storageWriteRequestsPerSecond;
    }

    /**
     * How many virtual CPUs this sample covers.
     */
    public function vcpus(): int
    {
        return count($this->cpuUsageDetailed);
    }

    /**
     * The busiest single vCPU, as a percentage - which a per-core bottleneck shows up in and
     * the average does not.
     */
    public function busiestCpuPercent(): ?float
    {
        return $this->cpuUsageDetailed === [] ? null : max($this->cpuUsageDetailed);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'cpu_usage_percent' => $this->cpuUsagePercent,
            'cpu_usage_detailed' => $this->cpuUsageDetailed,
            'memory_usage_bytes' => $this->memoryUsageBytes,
            'network_incoming_kbps' => $this->networkIncomingKbps,
            'network_outgoing_kbps' => $this->networkOutgoingKbps,
            'storage_usage_megabytes' => $this->storageUsageMegabytes,
            'storage_read_kbps' => $this->storageReadKbps,
            'storage_write_kbps' => $this->storageWriteKbps,
            'storage_read_requests_per_second' => $this->storageReadRequestsPerSecond,
            'storage_write_requests_per_second' => $this->storageWriteRequestsPerSecond,
        ];
    }
}
