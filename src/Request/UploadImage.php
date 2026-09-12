<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Enum\BackupReplacementStrategy;
use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Upload a disk image from a URL into one of a server's backup slots - the specification's
 * `UploadImageRequest`.
 *
 * THE TWO CONDITIONAL FIELDS ARE WHY THIS HAS NAMED CONSTRUCTORS RATHER THAN A CONSTRUCTOR.
 * The specification's rule is stated in prose and enforced by the API: `backup_type` is
 * required unless the strategy is `specified`, and `backup_id_to_replace` is required when it
 * is. Written as four constructors, each takes exactly what its strategy needs and the
 * impossible combinations cannot be expressed.
 *
 *     UploadImage::intoFreeSlot($url, BackupSlot::Temporary);   // fails if no slot is free
 *     UploadImage::replacingOldest($url, BackupSlot::Daily);    // destroys a backup
 *     UploadImage::replacing($url, 987654);                     // destroys that one
 *
 * THREE OF THE FOUR DESTROY AN EXISTING BACKUP. Only intoFreeSlot() does not - it fails
 * instead when there is no room, which is the safe default and the reason it is named for
 * what it does rather than for its strategy value.
 *
 * ONLY HTTP AND HTTPS SOURCES ARE SUPPORTED, and the URL must be reachable by BinaryLane
 * rather than by you.
 */
final class UploadImage implements \JsonSerializable
{
    public const MAX_LABEL = 250;

    private function __construct(
        public readonly string $url,
        public readonly BackupReplacementStrategy $replacementStrategy,
        public readonly ?BackupSlot $backupType = null,
        public readonly ?int $backupIdToReplace = null,
        private readonly ?string $label = null,
    ) {
    }

    /**
     * Use a free slot of this type, and fail when there is none.
     *
     * The only one of these that cannot destroy an existing backup.
     */
    public static function intoFreeSlot(string $url, BackupSlot $type): self
    {
        return new self(self::url($url), BackupReplacementStrategy::None, $type);
    }

    /**
     * Use a free slot of this type, or replace the oldest unlocked, unattached backup in it.
     */
    public static function replacingOldest(string $url, BackupSlot $type): self
    {
        return new self(self::url($url), BackupReplacementStrategy::Oldest, $type);
    }

    /**
     * Use a free slot of this type, or replace the newest unlocked, unattached backup in it.
     */
    public static function replacingNewest(string $url, BackupSlot $type): self
    {
        return new self(self::url($url), BackupReplacementStrategy::Newest, $type);
    }

    /**
     * Replace one particular backup, named by its image id.
     *
     * The one strategy that takes no backup type: the slot is whatever the named backup is in.
     */
    public static function replacing(string $url, int $backupId): self
    {
        if ($backupId < 1) {
            throw new InvalidArgumentException('A backup id to replace must be positive.');
        }

        return new self(self::url($url), BackupReplacementStrategy::Specified, null, $backupId);
    }

    /**
     * A label to identify the backup by afterwards. At most 250 characters.
     */
    public function withLabel(string $label): self
    {
        if (strlen($label) > self::MAX_LABEL) {
            throw new InvalidArgumentException(sprintf(
                'A backup label may be at most %d characters; %d were given.',
                self::MAX_LABEL,
                strlen($label)
            ));
        }

        return new self(
            $this->url,
            $this->replacementStrategy,
            $this->backupType,
            $this->backupIdToReplace,
            $label
        );
    }

    /**
     * Whether this upload can destroy an existing backup.
     */
    public function canReplace(): bool
    {
        return $this->replacementStrategy->canReplace();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'url' => $this->url,
            'replacement_strategy' => $this->replacementStrategy->value,
        ];

        if ($this->backupType !== null) {
            $payload['backup_type'] = $this->backupType->value;
        }

        if ($this->backupIdToReplace !== null) {
            $payload['backup_id_to_replace'] = $this->backupIdToReplace;
        }

        if ($this->label !== null) {
            $payload['label'] = $this->label;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function url(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('An image upload needs a source URL.');
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException(sprintf(
                'BinaryLane only fetches image uploads over HTTP and HTTPS; "%s" is neither.',
                $url
            ));
        }

        return $url;
    }
}
