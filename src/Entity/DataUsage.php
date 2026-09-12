<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * How much data transfer a server has used this billing period.
 *
 * THE INCLUDED ALLOWANCE IS POOLED ACROSS SERVERS, and the specification says so on the usage
 * field: with more than one server, the used figure is measured against the account's combined
 * allowance rather than this server's own. So `transferGigabytes` here is this server's
 * CONTRIBUTION to the pool, and a single server over its own number is not necessarily over
 * anything. Endpoint\DataUsages::total() adds them up, which is the comparison that means
 * something.
 *
 * TWO DATES THAT ARE NOT THE SAME. `expires` is when the billing period ends; `transferPeriodEnd`
 * is when the transfer limit period ended, if it has. They usually agree and are separate
 * fields because they need not.
 */
final class DataUsage implements \JsonSerializable
{
    /**
     * @param  int  $transferGigabytes  this server's contribution to the pooled allowance
     * @param  float  $currentTransferUsageGigabytes  usage measured against the POOL
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $serverId,
        public readonly int $transferGigabytes = 0,
        public readonly float $currentTransferUsageGigabytes = 0.0,
        public readonly ?\DateTimeImmutable $expires = null,
        public readonly ?\DateTimeImmutable $transferPeriodEnd = null,
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
            Cast::int($row['transfer_gigabytes'] ?? null) ?? 0,
            Cast::float($row['current_transfer_usage_gigabytes'] ?? null) ?? 0.0,
            Cast::datetime($row['expires'] ?? null),
            Cast::datetime($row['transfer_period_end'] ?? null),
            $row,
        );
    }

    /**
     * Usage as a percentage of the allowance.
     *
     * ON A MULTI-SERVER ACCOUNT THIS IS NOT A PER-SERVER FIGURE, because the usage is measured
     * against the pool - see the class note. Meaningful on an account with one server, and
     * indicative rather than exact otherwise.
     */
    public function usagePercent(): ?float
    {
        return $this->transferGigabytes > 0
            ? $this->currentTransferUsageGigabytes / $this->transferGigabytes * 100
            : null;
    }

    /**
     * How much of the allowance is left, in GB. Negative when it has been exceeded.
     */
    public function remainingGigabytes(): float
    {
        return $this->transferGigabytes - $this->currentTransferUsageGigabytes;
    }

    public function isOverAllowance(): bool
    {
        return $this->currentTransferUsageGigabytes > $this->transferGigabytes;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'server_id' => $this->serverId,
            'transfer_gigabytes' => $this->transferGigabytes,
            'current_transfer_usage_gigabytes' => $this->currentTransferUsageGigabytes,
            'expires' => $this->expires?->format(\DateTimeInterface::ATOM),
            'transfer_period_end' => $this->transferPeriodEnd?->format(\DateTimeInterface::ATOM),
        ];
    }
}
