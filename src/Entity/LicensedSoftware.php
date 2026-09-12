<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Software licensed on a particular server.
 *
 * `incompatible` IS A WARNING ABOUT WHAT HAPPENS NEXT, not a description of now. The
 * specification says incompatible software is "automatically removed at the next rebuild" -
 * so it is working today and will be gone after the next rebuild, with nothing else to
 * announce it. Worth surfacing before a rebuild rather than explaining afterwards.
 */
final class LicensedSoftware implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Software $software = null,
        public readonly int $licenceCount = 0,
        public readonly bool $incompatible = false,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::nested($row['software'] ?? null, Software::fromArray(...)),
            Cast::int($row['licence_count'] ?? null) ?? 0,
            Cast::bool($row['incompatible'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * Whether this licence will be dropped at the server's next rebuild.
     */
    public function willBeRemovedOnRebuild(): bool
    {
        return $this->incompatible;
    }

    /**
     * What these licences cost per month, in AU$.
     */
    public function monthlyCost(): float
    {
        return $this->software?->monthlyCost($this->licenceCount) ?? 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'software' => $this->software,
            'licence_count' => $this->licenceCount,
            'incompatible' => $this->incompatible,
        ];
    }
}
