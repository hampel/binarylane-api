<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\UserInteractionType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * An action that has stopped to ask a question.
 *
 * It stays `in-progress` while it waits, so nothing in the status says it is stuck. The
 * answer goes back through Actions::proceed(), and the two questions it can ask are both
 * decisions about destroying or accepting something - see UserInteractionType.
 */
final class UserInteractionRequired implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?UserInteractionType $interactionType = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            UserInteractionType::tryFrom(Cast::string($row['interaction_type'] ?? null) ?? ''),
            $row,
        );
    }

    /**
     * The question in words, or a fallback naming the raw type when this package has no case
     * for it - which is the situation where a caller most needs to be told something.
     */
    public function question(): string
    {
        if ($this->interactionType !== null) {
            return $this->interactionType->question();
        }

        $raw = Cast::string($this->raw['interaction_type'] ?? null);

        return $raw === null
            ? 'The action is waiting on a response, and did not say what kind.'
            : sprintf('The action is waiting on a response of an unrecognised kind: "%s".', $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : ['interaction_type' => $this->interactionType?->value];
    }
}
