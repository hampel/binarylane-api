<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Time-limited download URLs for every disk in an image.
 *
 * THESE URLS ARE THE CONTENTS OF A SERVER'S DISK, unauthenticated, until `expiry`. Anyone
 * holding one can download the whole image - so they do not belong in a log, a queue payload
 * or a URL that gets written down. Fetch them when the download is about to happen.
 *
 * ONLY USER-CREATED BACKUP IMAGES CAN BE DOWNLOADED AT ALL; the endpoint answers 403 - the
 * one 403 in the whole specification - for an account not permitted to export.
 */
final class ImageDownload implements \JsonSerializable
{
    /**
     * @param  list<ImageDiskDownload>  $disks
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?\DateTimeImmutable $expiry = null,
        public readonly array $disks = [],
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
            Cast::datetime($row['expiry'] ?? null),
            Cast::objects($row['disks'] ?? null, ImageDiskDownload::fromArray(...)),
            $row,
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiry === null) {
            return false;
        }

        return $this->expiry <= ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Seconds left before the URLs stop working.
     */
    public function expiresIn(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->expiry === null) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $this->expiry->getTimestamp() - $now->getTimestamp());
    }

    /**
     * The preferred URL for each disk, in order.
     *
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (ImageDiskDownload $disk): string => $disk->url(), $this->disks);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['download' => sprintf(
            'download URLs for %d disk(s) of image %d, expiring %s (withheld)',
            count($this->disks),
            $this->id,
            $this->expiry?->format(\DateTimeInterface::ATOM) ?? 'at an unstated time'
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'expiry' => $this->expiry?->format(\DateTimeInterface::ATOM),
            'disks' => $this->disks,
        ];
    }
}
