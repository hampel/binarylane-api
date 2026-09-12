<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Enum\ThresholdAlertType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * A change to one threshold alert - the specification's `ThresholdAlertRequest`.
 *
 * BOTH OPTIONAL FIELDS MEAN "LEAVE IT ALONE" WHEN OMITTED, so enabling an alert without
 * touching its value is `enable($type)` and nothing else.
 *
 * THE VALUE'S UNIT DEPENDS ON THE TYPE. Some of these are percentages and some are rates -
 * see ThresholdAlertType::isPercentage(), and the `unit` on the fetched alert. Setting a
 * network alert to 80 while thinking in percent produces an alert that fires constantly.
 *
 * The change action REPLACES nothing: ServerActions::changeThresholdAlerts() takes a list,
 * and alerts not in the list are left as they are.
 */
final class ThresholdAlert implements \JsonSerializable
{
    private function __construct(
        public readonly ThresholdAlertType $alertType,
        private readonly ?bool $enabled = null,
        private readonly ?int $value = null,
    ) {
        if ($value !== null && $value < 0) {
            throw new InvalidArgumentException('A threshold value cannot be negative.');
        }
    }

    /**
     * Turn an alert on, at a threshold.
     */
    public static function at(ThresholdAlertType $type, int $value): self
    {
        return new self($type, true, $value);
    }

    /**
     * Turn an alert on and leave its threshold where it is.
     */
    public static function enable(ThresholdAlertType $type): self
    {
        return new self($type, true);
    }

    /**
     * Turn an alert off. Its threshold is remembered.
     */
    public static function disable(ThresholdAlertType $type): self
    {
        return new self($type, false);
    }

    /**
     * Change a threshold without changing whether the alert is on.
     */
    public static function value(ThresholdAlertType $type, int $value): self
    {
        return new self($type, null, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['alert_type' => $this->alertType->value];

        if ($this->enabled !== null) {
            $payload['enabled'] = $this->enabled;
        }

        if ($this->value !== null) {
            $payload['value'] = $this->value;
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
