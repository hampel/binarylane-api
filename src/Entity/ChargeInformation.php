<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One charge contributing to the un-billed total.
 *
 * `ongoing` SEPARATES A RECURRING SERVICE FROM A ONE-OFF, which is the distinction that makes
 * a balance projectable: the ongoing charges are what will recur, the rest are what happened.
 */
final class ChargeInformation implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $created = null,
        public readonly string $description = '',
        public readonly float $total = 0.0,
        public readonly bool $ongoing = false,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::datetime($row['created'] ?? null),
            Cast::string($row['description'] ?? null) ?? '',
            Cast::float($row['total'] ?? null) ?? 0.0,
            Cast::bool($row['ongoing'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'created' => $this->created?->format(\DateTimeInterface::ATOM),
            'description' => $this->description,
            'total' => $this->total,
            'ongoing' => $this->ongoing,
        ];
    }
}
