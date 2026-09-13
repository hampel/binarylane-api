<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Download URLs for one disk inside an image.
 *
 * PREFER THE COMPRESSED ONE. The specification says so directly - "it is always preferable to
 * download the compressed image" - and the raw URL exists for tooling that cannot decompress.
 * A raw disk image is the full provisioned size; the compressed one is what was actually
 * written.
 */
final class ImageDiskDownload implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $compressedUrl = '',
        public readonly string $rawUrl = '',
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
            Cast::string($row['compressed_url'] ?? null) ?? '',
            Cast::string($row['raw_url'] ?? null) ?? '',
            $row,
        );
    }

    /**
     * The compressed URL, or the raw one when there is no compressed one.
     *
     * THE TWO ARE DIFFERENT FORMATS, so the fallback changes what arrives. A caller that names
     * the file after compression, or decompresses it, gets a raw disk image from this whenever
     * the compressed URL is absent - and finds out only after the whole disk has transferred.
     * Anything that depends on the format should read `compressedUrl` or `rawUrl` directly
     * rather than this.
     */
    public function url(): string
    {
        return $this->compressedUrl !== '' ? $this->compressedUrl : $this->rawUrl;
    }

    /**
     * The URLs are omitted: they are time-limited credentials for the contents of a server's
     * disk.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['disk' => sprintf('download URLs for disk %d (withheld)', $this->id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'compressed_url' => $this->compressedUrl,
            'raw_url' => $this->rawUrl,
        ];
    }
}
