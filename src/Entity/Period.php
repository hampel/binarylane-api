<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\DataInterval;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The window a sample set covers.
 *
 * `dataInterval` IS THE SPACING OF THE DATA POINTS, NOT THE COLLECTION INTERVAL - the
 * specification draws that distinction explicitly. It says how far apart the numbers are, not
 * how often the server was measured.
 */
final class Period implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $start = null,
        public readonly ?\DateTimeImmutable $end = null,
        public readonly ?DataInterval $dataInterval = null,
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
            DataInterval::tryFrom(Cast::string($row['data_interval'] ?? null) ?? ''),
            $row,
        );
    }

    /**
     * How long the window covers, in seconds.
     */
    public function seconds(): ?int
    {
        if ($this->start === null || $this->end === null) {
            return null;
        }

        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'start' => $this->start?->format(\DateTimeInterface::ATOM),
            'end' => $this->end?->format(\DateTimeInterface::ATOM),
            'data_interval' => $this->dataInterval?->value,
        ];
    }
}
