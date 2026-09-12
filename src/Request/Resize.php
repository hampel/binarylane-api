<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Change a server's plan, its options, its image or its licences - the specification's
 * `Resize`.
 *
 * THE MOST DANGEROUS CALL IN THIS API, and it does not look like it. BinaryLane's own note
 * reads: "**NB: This *may* be a destructive operation** (e.g. if a new base image is provided
 * the server will be rebuilt, or if the weekly backups are reduced to 0 all weekly backups
 * will be removed) **and no further confirmation will be requested.**"
 *
 * Both halves of that are worth restating, because they are different accidents:
 *
 *  - AN IMAGE CHANGE REBUILDS THE SERVER. Everything on the disks is discarded.
 *  - REDUCING A BACKUP COUNT DELETES THOSE BACKUPS. Setting `weeklyBackups` to 0 removes
 *    every weekly backup the server has - including the one you were relying on to undo
 *    whatever this resize was for.
 *
 * And the quiet one, from SizeOptions: EVERY OPTION IS AN ABSOLUTE VALUE. A resize built by
 * adding to the current numbers doubles them, and a resize built from
 * `SizeOptions::from($server->selectedSizeOptions)` pins all eight rather than leaving seven
 * alone.
 *
 *     Resize::toSize('std-2vcpu')
 *         ->withPreActionBackup(TakeBackup::intoFreeSlot(BackupSlot::Temporary))
 *
 * isDestructive() reports whether this particular request carries either of the two hazards,
 * so a confirmation prompt can ask the right question.
 */
final class Resize implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private readonly array $payload = [],
    ) {
    }

    /**
     * Change the base size - "change plan".
     */
    public static function toSize(string $size): self
    {
        $size = trim($size);

        if ($size === '') {
            throw new InvalidArgumentException('A resize to a different size needs a size slug.');
        }

        return new self(['size' => $size]);
    }

    /**
     * Change the options without changing the base size.
     */
    public static function options(SizeOptions $options): self
    {
        if ($options->isEmpty()) {
            throw new InvalidArgumentException(
                'A resize that changes no options and no size would do nothing; give it something to change.'
            );
        }

        return new self(['options' => $options->toArray()]);
    }

    /**
     * Change only the licences.
     *
     * @param  list<License>  $licenses
     */
    public static function licenses(array $licenses): self
    {
        return (new self())->withLicenses($licenses);
    }

    /**
     * The add-ons to end up with - memory, disk, transfer, addresses, backup retention.
     *
     * ABSOLUTE VALUES, and reducing a backup count deletes those backups. See SizeOptions.
     */
    public function withOptions(SizeOptions $options): self
    {
        return $options->isEmpty() ? $this : $this->with('options', $options->toArray());
    }

    /**
     * Rebuild the server on a different image as part of the resize.
     *
     * THIS IS THE DESTRUCTIVE HALF. Everything on the server's disks is discarded.
     */
    public function withImage(ChangeImage $image): self
    {
        return $this->with('change_image', $image->toArray());
    }

    /**
     * The licences the server should end up with.
     *
     * A REPLACEMENT, NOT AN ADDITION: the set given is the set the server has afterwards, so
     * an empty list removes them all.
     *
     * @param  list<License>  $licenses
     */
    public function withLicenses(array $licenses): self
    {
        return $this->with('change_licenses', [
            'licenses' => array_map(static fn (License $l): array => $l->toArray(), array_values($licenses)),
        ]);
    }

    /**
     * Take a backup before anything else happens.
     *
     * THE ONLY SAFETY NET THE API OFFERS on a destructive resize, and it is off by default.
     * Worth attaching whenever withImage() is used or a backup count is being reduced.
     */
    public function withPreActionBackup(TakeBackup $backup): self
    {
        return $this->with('pre_action_backup', $backup->toArray());
    }

    /**
     * Whether this request carries either of the two documented hazards.
     *
     * True when an image change is included - which rebuilds the server - or when the options
     * reduce a retained backup count below what the server currently has, which deletes the
     * difference. The second needs the server to compare against; without one, only the image
     * change can be seen.
     */
    public function isDestructive(?Server $current = null): bool
    {
        if (isset($this->payload['change_image'])) {
            return true;
        }

        $selected = $current?->selectedSizeOptions;
        $options = $this->payload['options'] ?? null;

        if ($selected === null || !is_array($options)) {
            return false;
        }

        foreach ([
            'daily_backups' => $selected->dailyBackups,
            'weekly_backups' => $selected->weeklyBackups,
            'monthly_backups' => $selected->monthlyBackups,
        ] as $key => $currentCount) {
            $asked = $options[$key] ?? null;

            if (is_int($asked) && $asked < $currentCount) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this request would destroy, in words - for a confirmation prompt.
     *
     * @return list<string>
     */
    public function hazards(?Server $current = null): array
    {
        $hazards = [];

        if (isset($this->payload['change_image'])) {
            $hazards[] = 'the server will be rebuilt on a new image and everything on its disks discarded';
        }

        $selected = $current?->selectedSizeOptions;
        $options = $this->payload['options'] ?? null;

        if ($selected !== null && is_array($options)) {
            foreach ([
                'daily_backups' => ['daily', $selected->dailyBackups],
                'weekly_backups' => ['weekly', $selected->weeklyBackups],
                'monthly_backups' => ['monthly', $selected->monthlyBackups],
            ] as $key => [$label, $currentCount]) {
                $asked = $options[$key] ?? null;

                if (is_int($asked) && $asked < $currentCount) {
                    $hazards[] = sprintf(
                        '%d of the %d retained %s backups will be deleted',
                        $currentCount - $asked,
                        $currentCount,
                        $label
                    );
                }
            }
        }

        return $hazards;
    }

    /**
     * Whether a backup is taken before the resize runs.
     */
    public function hasPreActionBackup(): bool
    {
        return isset($this->payload['pre_action_backup']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->payload === []) {
            throw new InvalidArgumentException(
                'A resize with nothing to change would do nothing; set a size, options, an image or licences.'
            );
        }

        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    private function with(string $key, mixed $value): self
    {
        return new self([...$this->payload, $key => $value]);
    }
}
