<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One disk image inside a backup.
 *
 * TWO SIZES, AND THEY ANSWER DIFFERENT QUESTIONS. `sizeGigabytes` is the COMPRESSED size of
 * the stored image - what it costs to keep. `minDiskSize` is the disk the target server must
 * have for it to be restored - what it costs to use. Restoring into a smaller server fails on
 * the second however small the first is.
 */
final class BackupDisk implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly float $sizeGigabytes = 0.0,
        public readonly int $minDiskSize = 0,
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
            Cast::float($row['size_gigabytes'] ?? null) ?? 0.0,
            Cast::int($row['min_disk_size'] ?? null) ?? 0,
            Cast::string($row['description'] ?? null),
            $row,
        );
    }

    /**
     * Whether a server with this much disk could take this image.
     */
    public function fitsIn(int $diskGigabytes): bool
    {
        return $diskGigabytes >= $this->minDiskSize;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'size_gigabytes' => $this->sizeGigabytes,
            'min_disk_size' => $this->minDiskSize,
            'description' => $this->description,
        ];
    }
}
