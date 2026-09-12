<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * When the next scheduled backup is expected to run.
 *
 * A WINDOW, NOT A TIME, and the specification is explicit that both ends are approximate.
 * `start` is the earliest the backup might begin and `end` the latest it is expected to -
 * so scheduling work around it means avoiding the whole span, not the instant.
 */
final class BackupWindow implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $start = null,
        public readonly ?\DateTimeImmutable $end = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::datetime($row['start'] ?? null),
            Cast::datetime($row['end'] ?? null),
            $row,
        );
    }

    /**
     * Whether a given instant falls inside the window. Now, when not told otherwise.
     */
    public function contains(?\DateTimeImmutable $when = null): bool
    {
        if ($this->start === null || $this->end === null) {
            return false;
        }

        $when ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $when >= $this->start && $when <= $this->end;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'start' => $this->start?->format(\DateTimeInterface::ATOM),
            'end' => $this->end?->format(\DateTimeInterface::ATOM),
        ];
    }
}
