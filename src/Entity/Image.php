<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\ImageStatus;
use Hampel\BinaryLane\Api\Enum\ImageType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * An operating system image, a backup, or an uploaded disk image.
 *
 * ONE CLASS, THREE DIFFERENT THINGS, and `type` is what says which - which matters because
 * half the fields are documented as meaning something only "if this is an operating system
 * image" and the other half only if it is a backup. A backup has a null `slug` and a
 * populated `backupInfo`; a distribution is the other way round.
 *
 * `slug` IS AN ALTERNATIVE IDENTIFIER AND ONLY DISTRIBUTIONS HAVE ONE. `GET
 * /v2/images/{image_id_or_slug}` takes either, so `ubuntu-24-04-lts` works for a
 * distribution and nothing but the numeric id works for a backup.
 *
 * TWO SIZES AGAIN, and the same distinction as BackupDisk: `sizeGigabytes` is what the image
 * occupies, `minDiskSize` is the disk a server needs to take it. A rebuild onto a server
 * smaller than `minDiskSize` is refused, and `minMemoryMegabytes` refuses independently of
 * that.
 *
 * `distributionSurcharges` BEING PRESENT MEANS THE IMAGE COSTS EXTRA - a licensed operating
 * system. The field is null when it does not.
 */
final class Image implements \JsonSerializable
{
    /**
     * @param  list<string>  $regions  region slugs where this image can be used
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly ?ImageType $type = null,
        public readonly ?ImageStatus $status = null,
        public readonly bool $public = false,
        public readonly array $regions = [],
        public readonly ?string $slug = null,
        public readonly ?string $distribution = null,
        public readonly ?string $fullName = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly int $minDiskSize = 0,
        public readonly float $sizeGigabytes = 0.0,
        public readonly ?int $minMemoryMegabytes = null,
        public readonly ?string $description = null,
        public readonly ?string $errorMessage = null,
        public readonly ?DistributionSurcharges $distributionSurcharges = null,
        public readonly ?DistributionInfo $distributionInfo = null,
        public readonly ?BackupInfo $backupInfo = null,
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
            ImageType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            ImageStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::bool($row['public'] ?? null) ?? false,
            Cast::strings($row['regions'] ?? null),
            Cast::string($row['slug'] ?? null),
            Cast::string($row['distribution'] ?? null),
            Cast::string($row['full_name'] ?? null),
            Cast::datetime($row['created_at'] ?? null),
            Cast::int($row['min_disk_size'] ?? null) ?? 0,
            Cast::float($row['size_gigabytes'] ?? null) ?? 0.0,
            Cast::int($row['min_memory_megabytes'] ?? null),
            Cast::string($row['description'] ?? null),
            Cast::string($row['error_message'] ?? null),
            Cast::nested($row['distribution_surcharges'] ?? null, DistributionSurcharges::fromArray(...)),
            Cast::nested($row['distribution_info'] ?? null, DistributionInfo::fromArray(...)),
            Cast::nested($row['backup_info'] ?? null, BackupInfo::fromArray(...)),
            $row,
        );
    }

    /**
     * Whether the image is usable right now.
     */
    public function isAvailable(): bool
    {
        return $this->status?->isUsable() ?? false;
    }

    /**
     * Whether this is a base operating system image rather than a backup or an upload.
     */
    public function isDistribution(): bool
    {
        return $this->backupInfo === null && $this->type !== ImageType::Backup;
    }

    public function isBackup(): bool
    {
        return $this->backupInfo !== null || $this->type === ImageType::Backup;
    }

    /**
     * Whether the image is offered in a region.
     */
    public function isAvailableIn(string $region): bool
    {
        return $this->isAvailable() && in_array($region, $this->regions, true);
    }

    /**
     * Whether a server of this shape could take this image.
     *
     * Both limits, because they refuse independently and produce the same 400.
     */
    public function fits(int $diskGigabytes, ?int $memoryMegabytes = null): bool
    {
        if ($diskGigabytes < $this->minDiskSize) {
            return false;
        }

        return $memoryMegabytes === null
            || $this->minMemoryMegabytes === null
            || $memoryMegabytes >= $this->minMemoryMegabytes;
    }

    /**
     * Whether using this image adds a monthly charge beyond the size's own price.
     */
    public function hasSurcharge(): bool
    {
        return $this->distributionSurcharges !== null && !$this->distributionSurcharges->isEmpty();
    }

    /**
     * What this image adds to the monthly bill for a server of this shape, in AU$.
     */
    public function monthlySurcharge(int $memoryMegabytes, int $vcpus): float
    {
        return $this->distributionSurcharges?->monthlyFor($memoryMegabytes, $vcpus) ?? 0.0;
    }

    /**
     * Whether image creation failed - an upload that did not process, a backup that did not
     * complete. `errorMessage` is what there is to say about it.
     */
    public function hasFailed(): bool
    {
        return $this->errorMessage !== null && trim($this->errorMessage) !== '';
    }

    /**
     * What to pass where the API takes an id or a slug: the slug when there is one, and the
     * id otherwise - which is every backup.
     */
    public function reference(): string
    {
        return $this->slug !== null && trim($this->slug) !== '' ? $this->slug : (string) $this->id;
    }

    /**
     * The most specific name there is, for a display.
     */
    public function describe(): string
    {
        foreach ([$this->fullName, $this->name] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'image ' . $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'public' => $this->public,
            'regions' => $this->regions,
            'slug' => $this->slug,
            'distribution' => $this->distribution,
            'full_name' => $this->fullName,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'min_disk_size' => $this->minDiskSize,
            'size_gigabytes' => $this->sizeGigabytes,
            'min_memory_megabytes' => $this->minMemoryMegabytes,
            'description' => $this->description,
            'error_message' => $this->errorMessage,
            'distribution_surcharges' => $this->distributionSurcharges,
            'distribution_info' => $this->distributionInfo,
            'backup_info' => $this->backupInfo,
        ];
    }
}
