<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The family a size belongs to - what it is optimised for.
 *
 * An object rather than an enum on purpose: BinaryLane adds size types, and a slug this
 * package had never heard of must not read as null on a size that is perfectly usable.
 */
final class SizeType implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $slug = '',
        public readonly string $name = '',
        public readonly ?string $description = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['slug'] ?? null) ?? '',
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['description'] ?? null),
            $row,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
