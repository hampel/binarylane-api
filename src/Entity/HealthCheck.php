<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\HealthCheckProtocol;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * How a load balancer decides whether a server is healthy.
 *
 * `both` IS PER-PROTOCOL, NOT "EITHER WILL DO". A server failing the HTTP check is removed
 * from the HTTP pool and left in the HTTPS one - the specification says exactly that, and it
 * is the opposite of what the word suggests. A server serving HTTPS correctly and returning
 * 500 on plain HTTP stays in half the rotation.
 *
 * THE PATH IS THE WHOLE CHECK. There is no interval, no timeout, no threshold and no expected
 * status code to configure: a path, and a protocol. Everything else is BinaryLane's.
 */
final class HealthCheck implements \JsonSerializable
{
    /**
     * What the API uses when no path is given.
     */
    public const DEFAULT_PATH = '/';

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?HealthCheckProtocol $protocol = null,
        public readonly string $path = self::DEFAULT_PATH,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            HealthCheckProtocol::tryFrom(Cast::string($row['protocol'] ?? null) ?? ''),
            Cast::string($row['path'] ?? null) ?? self::DEFAULT_PATH,
            $row,
        );
    }

    /**
     * Whether a failure on one protocol removes the server from that protocol's pool only.
     */
    public function isPerProtocol(): bool
    {
        return $this->protocol === HealthCheckProtocol::Both;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'protocol' => $this->protocol?->value,
            'path' => $this->path,
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }
}
