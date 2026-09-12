<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\LoadBalancerStatus;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A load balancer.
 *
 * A NULL `region` MEANS ANYCAST, not "unknown". The specification says a load balancer
 * created without a region is anycast, and that is how one reports itself afterwards -
 * isAnycast() reads it that way rather than treating the null as missing data.
 *
 * `serverIds` IS THE POOL, AND IT IS A SET YOU REPLACE. The update endpoint takes the whole
 * list, so adding one server through it means sending the others; the dedicated
 * `POST /servers` and `DELETE /servers` operations are the additive ones. See
 * Endpoint\LoadBalancers.
 *
 * THE POOL IS NOT THE HEALTHY POOL. `serverIds` is what has been assigned; which of them are
 * currently in rotation is decided by the health check and is not reported here.
 */
final class LoadBalancer implements \JsonSerializable
{
    /**
     * @param  list<ForwardingRule>  $forwardingRules
     * @param  list<int>  $serverIds  the assigned pool, not the healthy pool
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly string $ip = '',
        public readonly ?LoadBalancerStatus $status = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly array $forwardingRules = [],
        public readonly ?HealthCheck $healthCheck = null,
        public readonly ?Region $region = null,
        public readonly array $serverIds = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['id'] ?? null) ?? 0,
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['ip'] ?? null) ?? '',
            LoadBalancerStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::datetime($row['created_at'] ?? null),
            Cast::objects($row['forwarding_rules'] ?? null, ForwardingRule::fromArray(...)),
            Cast::nested($row['health_check'] ?? null, HealthCheck::fromArray(...)),
            Cast::nested($row['region'] ?? null, Region::fromArray(...)),
            Cast::ints($row['server_ids'] ?? null),
            $row,
        );
    }

    /**
     * Whether this load balancer is anycast rather than regional - which is what a null region
     * means here.
     */
    public function isAnycast(): bool
    {
        return $this->region === null;
    }

    public function isAvailable(): bool
    {
        return $this->status?->isAvailable() ?? false;
    }

    /**
     * Whether a server is assigned to this load balancer. Not whether it is healthy.
     */
    public function hasServer(int $serverId): bool
    {
        return in_array($serverId, $this->serverIds, true);
    }

    /**
     * Whether the pool is empty - a load balancer forwarding to nothing, which answers every
     * request with an error and looks like a network problem.
     */
    public function isEmpty(): bool
    {
        return $this->serverIds === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'ip' => $this->ip,
            'status' => $this->status?->value,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'forwarding_rules' => $this->forwardingRules,
            'health_check' => $this->healthCheck,
            'region' => $this->region,
            'server_ids' => $this->serverIds,
        ];
    }
}
