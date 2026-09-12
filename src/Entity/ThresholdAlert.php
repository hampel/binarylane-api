<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\ThresholdAlertType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One monitoring threshold on a server, and where it currently stands.
 *
 * `unit` IS THE FIELD THAT SAVES YOU. The alert `value` is a bare number whose meaning
 * depends on the type - a percentage for some, a rate for others - and the API tells you
 * which in `unit` ("%", "requests/second"). Setting a network alert to 80 while thinking in
 * percent is the mistake; see ThresholdAlertType::isPercentage().
 *
 * `lastRaised` AND `lastCleared` TOGETHER SAY WHETHER IT IS CURRENTLY FIRING, and neither
 * says so alone. Raised after cleared means it is up now. isCurrentlyRaised() is that
 * comparison, and it also handles the case of an alert raised and never cleared.
 */
final class ThresholdAlert implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?ThresholdAlertType $alertType = null,
        public readonly string $name = '',
        public readonly string $unit = '',
        public readonly string $description = '',
        public readonly bool $enabled = false,
        public readonly int $value = 0,
        public readonly ?int $currentValue = null,
        public readonly ?\DateTimeImmutable $lastRaised = null,
        public readonly ?\DateTimeImmutable $lastCleared = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            ThresholdAlertType::tryFrom(Cast::string($row['alert_type'] ?? null) ?? ''),
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['unit'] ?? null) ?? '',
            Cast::string($row['description'] ?? null) ?? '',
            Cast::bool($row['enabled'] ?? null) ?? false,
            Cast::int($row['value'] ?? null) ?? 0,
            Cast::int($row['current_value'] ?? null),
            Cast::datetime($row['last_raised'] ?? null),
            Cast::datetime($row['last_cleared'] ?? null),
            $row,
        );
    }

    /**
     * Whether the alert is above its threshold right now.
     *
     * Read from the timestamps rather than from `currentValue`, because the alert period is
     * an average over time and the current value is a sample of it - the two disagree at the
     * edges, and the timestamps are what BinaryLane actually acted on.
     */
    public function isCurrentlyRaised(): bool
    {
        if ($this->lastRaised === null) {
            return false;
        }

        return $this->lastCleared === null || $this->lastRaised > $this->lastCleared;
    }

    /**
     * Whether the last measurement was over the threshold, which is a different question from
     * whether the alert has fired - see isCurrentlyRaised().
     */
    public function isOverThreshold(): bool
    {
        return $this->currentValue !== null && $this->currentValue >= $this->value;
    }

    /**
     * Whether this alert is capable of firing at all. A disabled alert still reports a value
     * and a current value; it just never warns anyone.
     */
    public function isActive(): bool
    {
        return $this->enabled;
    }

    /**
     * The current value with its unit, for a display - "87%", "412 requests/second".
     */
    public function describeCurrent(): string
    {
        if ($this->currentValue === null) {
            return 'no measurement';
        }

        return $this->unit === '%'
            ? $this->currentValue . '%'
            : trim($this->currentValue . ' ' . $this->unit);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'alert_type' => $this->alertType?->value,
            'name' => $this->name,
            'unit' => $this->unit,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'value' => $this->value,
            'current_value' => $this->currentValue,
            'last_raised' => $this->lastRaised?->format(\DateTimeInterface::ATOM),
            'last_cleared' => $this->lastCleared?->format(\DateTimeInterface::ATOM),
        ];
    }
}
