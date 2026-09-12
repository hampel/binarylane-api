<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * How far through an action is.
 *
 * `percentComplete` IS AN ESTIMATE, and the specification says so in as many words. It is
 * fine to show and wrong to make a decision on - a progress bar, not a condition. The
 * condition is the action's STATUS.
 *
 * `currentStepDetail` is the field worth surfacing to a person while they wait: on a long
 * offsite backup it carries the upload speed and an ETA, which is the only part of this
 * object that answers "is it actually doing anything".
 */
final class ActionProgress implements \JsonSerializable
{
    /**
     * @param  list<string>  $completedSteps
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $percentComplete = 0,
        public readonly ?string $currentStep = null,
        public readonly ?string $currentStepDetail = null,
        public readonly array $completedSteps = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['percent_complete'] ?? null) ?? 0,
            Cast::string($row['current_step'] ?? null),
            Cast::string($row['current_step_detail'] ?? null),
            Cast::strings($row['completed_steps'] ?? null),
            $row,
        );
    }

    /**
     * The current step and its detail on one line, for a status display.
     */
    public function describe(): string
    {
        $parts = array_filter([$this->currentStep, $this->currentStepDetail]);

        return $parts === [] ? '' : implode(' - ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'percent_complete' => $this->percentComplete,
            'current_step' => $this->currentStep,
            'current_step_detail' => $this->currentStepDetail,
            'completed_steps' => $this->completedSteps,
        ];
    }
}
