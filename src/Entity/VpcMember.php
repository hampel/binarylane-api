<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\ResourceType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A resource inside a VPC.
 *
 * `resourceId` IS A STRING HERE, and it is an integer everywhere else in this API - the
 * specification declares it as a string on this schema alone. serverId() converts it for the
 * common case of a member that is a server, so a VPC listing can be joined to
 * `Servers::get()` without a cast at the call site.
 */
final class VpcMember implements \JsonSerializable
{
    /**
     * @param  string  $resourceId  a string on this schema, unlike everywhere else
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $name = '',
        public readonly ?ResourceType $resourceType = null,
        public readonly string $resourceId = '',
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['name'] ?? null) ?? '',
            ResourceType::tryFrom(Cast::string($row['resource_type'] ?? null) ?? ''),
            Cast::string($row['resource_id'] ?? null) ?? '',
            Cast::datetime($row['created_at'] ?? null),
            $row,
        );
    }

    public function isServer(): bool
    {
        return $this->resourceType === ResourceType::Server;
    }

    /**
     * The member's id as an integer, or null when it is not one - which is what every other
     * endpoint takes.
     */
    public function id(): ?int
    {
        return Cast::int($this->resourceId);
    }

    /**
     * The server id, when this member is a server.
     */
    public function serverId(): ?int
    {
        return $this->isServer() ? $this->id() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'name' => $this->name,
            'resource_type' => $this->resourceType?->value,
            'resource_id' => $this->resourceId,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
