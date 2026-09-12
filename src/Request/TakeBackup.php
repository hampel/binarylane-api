<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Enum\BackupReplacementStrategy;
use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Take a backup now - the specification's `TakeBackup`.
 *
 * Used two ways: as the `take_backup` action on its own, and as the `pre_action_backup` on a
 * Resize, which is how a destructive resize is made recoverable.
 *
 * SAME CONDITIONAL RULE AS AN UPLOAD, so the same four named constructors: `backup_type` is
 * required unless the strategy is `specified`, and `backup_id_to_replace` is required when it
 * is. Three of the four destroy an existing backup; intoFreeSlot() is the one that fails
 * instead.
 *
 *     TakeBackup::intoFreeSlot(BackupSlot::Temporary)->withLabel('before the upgrade');
 *
 * A TEMPORARY BACKUP IS KEPT FOR AT MOST SEVEN DAYS, which makes it the right slot for
 * "before I do something risky" and the wrong one for anything that has to survive.
 */
final class TakeBackup implements \JsonSerializable
{
    public const MAX_LABEL = 250;

    private function __construct(
        public readonly BackupReplacementStrategy $replacementStrategy,
        public readonly ?BackupSlot $backupType = null,
        public readonly ?int $backupIdToReplace = null,
        private readonly ?string $label = null,
    ) {
    }

    /**
     * Use a free slot of this type, and fail when there is none. Destroys nothing.
     */
    public static function intoFreeSlot(BackupSlot $type): self
    {
        return new self(BackupReplacementStrategy::None, $type);
    }

    /**
     * Use a free slot, or replace the oldest unlocked, unattached backup of this type.
     */
    public static function replacingOldest(BackupSlot $type): self
    {
        return new self(BackupReplacementStrategy::Oldest, $type);
    }

    /**
     * Use a free slot, or replace the newest unlocked, unattached backup of this type.
     */
    public static function replacingNewest(BackupSlot $type): self
    {
        return new self(BackupReplacementStrategy::Newest, $type);
    }

    /**
     * Replace one particular backup, by image id.
     */
    public static function replacing(int $backupId): self
    {
        if ($backupId < 1) {
            throw new InvalidArgumentException('A backup id to replace must be positive.');
        }

        return new self(BackupReplacementStrategy::Specified, null, $backupId);
    }

    /**
     * A label to find the backup by afterwards. At most 250 characters.
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

        return new self($this->replacementStrategy, $this->backupType, $this->backupIdToReplace, $label);
    }

    /**
     * Whether taking this backup can destroy an existing one.
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
        $payload = ['replacement_strategy' => $this->replacementStrategy->value];

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
}
