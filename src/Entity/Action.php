<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\ActionStatus;
use Hampel\BinaryLane\Api\Enum\ResourceType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A unit of work the API is doing, or has done.
 *
 * THIS IS HOW NEARLY EVERY MUTATION ON THIS API ANSWERS. Powering a server on, resizing it,
 * rebuilding it, taking a backup, creating a server - none of those have finished when the
 * call returns. What comes back is this: a record with an id you can ask about again.
 * Endpoint\Actions::await() is the loop, and it exists because writing that loop correctly
 * needs to know about the three ways an action stops without completing.
 *
 * THE THREE STALLS, which a naive `while ($action->status !== 'completed')` never escapes:
 *
 *  - `errored`. Finished, unsuccessfully. `reason` is the explanation.
 *  - `userInteractionRequired` is not null. Still `in-progress`, and will stay that way
 *    until Actions::proceed() answers the question. Nothing times out on the API's side.
 *  - `blockingInvoiceId` is not null. Held up by an invoice that needs paying. Also still
 *    `in-progress`, and also indefinitely.
 *
 * `resultData` IS WHERE AN ANSWER COMES BACK when the action was really a question - the
 * uptime action puts the uptime there, `is_running` puts the answer there. It is a string
 * whatever the action, so a caller reading it knows what shape to expect from what it asked.
 */
final class Action implements \JsonSerializable
{
    /**
     * @param  string  $type  the action's own name - `power_on`, `rebuild`, `take_backup`.
     *                        A plain string rather than an enum because the API adds actions
     *                        and an unknown one must not become null here
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?ActionStatus $status = null,
        public readonly string $type = '',
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?ResourceType $resourceType = null,
        public readonly ?int $resourceId = null,
        public readonly ?Region $region = null,
        public readonly ?string $regionSlug = null,
        public readonly string $title = '',
        public readonly string $reason = '',
        public readonly ?ActionProgress $progress = null,
        public readonly ?string $resultData = null,
        public readonly ?int $blockingInvoiceId = null,
        public readonly ?UserInteractionRequired $userInteractionRequired = null,
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
            ActionStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::string($row['type'] ?? null) ?? '',
            Cast::datetime($row['started_at'] ?? null),
            Cast::datetime($row['completed_at'] ?? null),
            ResourceType::tryFrom(Cast::string($row['resource_type'] ?? null) ?? ''),
            Cast::int($row['resource_id'] ?? null),
            Cast::nested($row['region'] ?? null, Region::fromArray(...)),
            Cast::string($row['region_slug'] ?? null),
            Cast::string($row['title'] ?? null) ?? '',
            Cast::string($row['reason'] ?? null) ?? '',
            Cast::nested($row['progress'] ?? null, ActionProgress::fromArray(...)),
            Cast::string($row['result_data'] ?? null),
            Cast::int($row['blocking_invoice_id'] ?? null),
            Cast::nested($row['user_interaction_required'] ?? null, UserInteractionRequired::fromArray(...)),
            $row,
        );
    }

    /**
     * Whether the API has stopped working on this, either way.
     */
    public function isFinished(): bool
    {
        return $this->status?->isFinished() ?? false;
    }

    /**
     * Whether it finished and worked.
     */
    public function isSuccessful(): bool
    {
        return $this->status?->isSuccessful() ?? false;
    }

    public function hasFailed(): bool
    {
        return $this->status?->hasFailed() ?? false;
    }

    /**
     * Whether the API is still working on it AND is not waiting for anything.
     *
     * The distinction from `status === InProgress` is the whole point: an action waiting on a
     * question or on an invoice is also "in progress" and will never move on its own.
     */
    public function isRunning(): bool
    {
        return ($this->status?->isRunning() ?? false) && !$this->isBlocked();
    }

    /**
     * Whether the action has stopped and will not resume without something happening outside
     * the API - an answer, or a payment.
     */
    public function isBlocked(): bool
    {
        return $this->needsInteraction() || $this->isBlockedByInvoice();
    }

    /**
     * Whether the action is waiting on an answer. Actions::proceed() is the answer.
     */
    public function needsInteraction(): bool
    {
        return $this->userInteractionRequired !== null;
    }

    /**
     * Whether the action is held up by an unpaid invoice. `blockingInvoiceId` names it, and
     * Billing::invoice() fetches it.
     */
    public function isBlockedByInvoice(): bool
    {
        return $this->blockingInvoiceId !== null;
    }

    /**
     * How long the action took, or has taken so far.
     *
     * Null when the API did not send a `started_at` that could be parsed. A running action
     * measures against now, which is why this is a method rather than a property.
     */
    public function duration(?\DateTimeImmutable $now = null): ?\DateInterval
    {
        if ($this->startedAt === null) {
            return null;
        }

        $end = $this->completedAt ?? $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->startedAt->diff($end);
    }

    /**
     * A single line saying what this action is and where it has got to - for a log, or for a
     * progress display that has one row per action.
     */
    public function describe(): string
    {
        $parts = [sprintf('#%d %s', $this->id, $this->title !== '' ? $this->title : $this->type)];

        $parts[] = $this->status->value ?? 'unknown status';

        if ($this->needsInteraction()) {
            $parts[] = 'waiting on: ' . ($this->userInteractionRequired?->question() ?? '');
        } elseif ($this->isBlockedByInvoice()) {
            $parts[] = sprintf('blocked by invoice %d', (int) $this->blockingInvoiceId);
        } elseif ($this->reason !== '') {
            $parts[] = $this->reason;
        }

        return implode(' - ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'status' => $this->status?->value,
            'type' => $this->type,
            'started_at' => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'resource_type' => $this->resourceType?->value,
            'resource_id' => $this->resourceId,
            'region_slug' => $this->regionSlug,
            'title' => $this->title,
            'reason' => $this->reason,
            'progress' => $this->progress,
            'result_data' => $this->resultData,
            'blocking_invoice_id' => $this->blockingInvoiceId,
            'user_interaction_required' => $this->userInteractionRequired,
        ];
    }
}
