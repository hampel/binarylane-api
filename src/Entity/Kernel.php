<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A boot kernel available to a server.
 *
 * Servers::kernels() lists the ones a particular server may use; the ChangeKernel action
 * selects one by id.
 */
final class Kernel implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
        public readonly ?string $version = null,
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
            Cast::string($row['name'] ?? null),
            Cast::string($row['version'] ?? null),
            $row,
        );
    }

    /**
     * Name and version on one line, for a selection list.
     */
    public function describe(): string
    {
        return trim(($this->name ?? '') . ' ' . ($this->version ?? '')) ?: ('kernel ' . $this->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
