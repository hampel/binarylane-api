<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A CPU model, and therefore a set of CPU flags, a server can be pinned to.
 *
 * Selected through AdvancedServerFeatures. A null selection there means BinaryLane chooses,
 * which is what nearly everything wants - pinning a model narrows which hosts the server can
 * run on.
 */
final class ProcessorModel implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
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
            Cast::int($row['id'] ?? null) ?? 0,
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
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
