<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Summary information about the physical host a server runs on.
 *
 * `displayName` IS EMPTY FOR A DEDICATED HOST - that is how the specification says a
 * dedicated host is reported, rather than with a flag. isDedicated() reads it that way.
 *
 * `statusPage` is normally only set when the host has a problem, so its presence is itself
 * the signal worth acting on.
 */
final class Host implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $displayName = '',
        public readonly ?int $uptimeMs = null,
        public readonly ?string $statusPage = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['display_name'] ?? null) ?? '',
            Cast::int($row['uptime_ms'] ?? null),
            Cast::string($row['status_page'] ?? null),
            $row,
        );
    }

    /**
     * Whether this is a dedicated host - which the API signals with an empty display name.
     */
    public function isDedicated(): bool
    {
        return trim($this->displayName) === '';
    }

    /**
     * Whether BinaryLane has published a status page for this host, which it normally only
     * does when something is wrong with it.
     */
    public function hasStatusPage(): bool
    {
        return $this->statusPage !== null && trim($this->statusPage) !== '';
    }

    /**
     * The host's uptime in whole seconds.
     */
    public function uptimeSeconds(): ?int
    {
        return $this->uptimeMs === null ? null : intdiv($this->uptimeMs, 1000);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'display_name' => $this->displayName,
            'uptime_ms' => $this->uptimeMs,
            'status_page' => $this->statusPage,
        ];
    }
}
