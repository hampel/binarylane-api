<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A DNS zone held by BinaryLane.
 *
 * A ZONE HERE IS NOT NECESSARILY A ZONE IN USE, and this is the field that catches people:
 * `currentNameservers` is what the domain's authoritative nameservers ACTUALLY are right now,
 * read from the public DNS - not what BinaryLane would like them to be. A domain can be added
 * here, have records created on it, and be serving none of them, because its registrar still
 * points somewhere else.
 *
 * The specification says the same about `ttl` and `zoneFile`: both describe "what it would be
 * if the authority was delegated to us". isDelegatedTo() compares the two, and
 * Endpoint\Domains::publicNameservers() is the list to compare against.
 *
 * `zoneFile` ALWAYS CARRIES A SERIAL OF 0 rather than the real one, which the specification
 * states outright - so it cannot be used to tell whether a zone has changed.
 */
final class Domain implements \JsonSerializable
{
    /**
     * @param  list<string>  $currentNameservers  what the domain's nameservers really are
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly array $currentNameservers = [],
        public readonly ?int $ttl = null,
        public readonly string $zoneFile = '',
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
            Cast::strings($row['current_nameservers'] ?? null),
            Cast::int($row['ttl'] ?? null),
            Cast::string($row['zone_file'] ?? null) ?? '',
            $row,
        );
    }

    /**
     * Whether this domain's authority actually rests with the given nameservers - which is
     * what decides whether the records in this zone do anything.
     *
     * Compares case-insensitively and ignores trailing dots, because a nameserver read out of
     * public DNS may carry one and the configured list usually does not. True only when EVERY
     * current nameserver is in the given list: a domain half-delegated is not delegated.
     *
     * @param  list<string>  $nameservers  Endpoint\Domains::publicNameservers()
     */
    public function isDelegatedTo(array $nameservers): bool
    {
        if ($this->currentNameservers === []) {
            return false;
        }

        $expected = array_map(self::normalise(...), $nameservers);

        foreach ($this->currentNameservers as $current) {
            if (!in_array(self::normalise($current), $expected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the domain resolves to any nameservers at all.
     *
     * An empty list means public DNS had no answer - an unregistered domain, or one whose
     * delegation has lapsed.
     */
    public function isResolvable(): bool
    {
        return $this->currentNameservers !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'current_nameservers' => $this->currentNameservers,
            'ttl' => $this->ttl,
            'zone_file' => $this->zoneFile,
        ];
    }

    public function __toString(): string
    {
        return $this->name;
    }

    private static function normalise(string $nameserver): string
    {
        return strtolower(rtrim(trim($nameserver), '.'));
    }
}
