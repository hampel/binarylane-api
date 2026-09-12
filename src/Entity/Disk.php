<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One virtual disk attached to a server.
 *
 * THE PRIMARY DISK IS NOT LIKE THE OTHERS. It is the one the operating system is installed
 * on, it cannot be deleted, and its size is governed by the server's SIZE rather than by the
 * disk actions - resizing it means resizing the server. `ResizeDisk` and `DeleteDisk` are for
 * the additional disks, and asking them to touch the primary is a 400.
 *
 * `sizeGigabytes` IS A FLOAT because BinaryLane reports fractional disk sizes; and a GB here
 * is 1024³ bytes, per the units table in the API's own introduction, not 10⁹.
 */
final class Disk implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly float $sizeGigabytes = 0.0,
        public readonly ?string $description = null,
        public readonly bool $primary = false,
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
            Cast::float($row['size_gigabytes'] ?? null) ?? 0.0,
            Cast::string($row['description'] ?? null),
            Cast::bool($row['primary'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * Whether this disk can be resized or deleted through the disk actions.
     */
    public function isAdditional(): bool
    {
        return !$this->primary;
    }

    /**
     * The size in bytes, with BinaryLane's binary gigabyte.
     */
    public function sizeBytes(): int
    {
        return (int) round($this->sizeGigabytes * 1024 ** 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'size_gigabytes' => $this->sizeGigabytes,
            'description' => $this->description,
            'primary' => $this->primary,
        ];
    }
}
