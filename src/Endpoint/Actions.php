<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Actions: the record of everything the API is doing or has done for this account.
 *
 * `/v2/actions`
 *
 * Most of what matters here is await(). Nearly every mutation on this API answers with an
 * action rather than with a result, so "did the resize work" is a question this endpoint
 * answers and no other one can.
 *
 *     $action = $binarylane->serverActions()->powerOn(1234);
 *     $binarylane->actions()->await($action);          // raises if it failed
 *
 * THE ACTION LIST IS ALSO THE AUDIT LOG, and it is the right place to look after a request
 * that timed out or died mid-flight: `each()` filtered by resource id says whether the
 * mutation was accepted, which is the difference between retrying safely and rebuilding a
 * server twice.
 */
final class Actions extends Endpoint
{
    /**
     * The envelope key a list of actions arrives under.
     */
    public const COLLECTION = 'actions';

    /**
     * How long await() waits before giving up, when it is not told.
     *
     * TEN MINUTES, WHICH IS NOT LONG ENOUGH FOR EVERYTHING. A power-on is seconds; a rebuild,
     * a region change or a restore from an offsite backup can run far longer than this.
     * Timing out does not cancel anything - see ActionTimedOutException - so the default is
     * set for the common case and the long ones are expected to pass their own.
     */
    public const DEFAULT_TIMEOUT = 600;

    /**
     * How long await() waits between polls, when it is not told.
     */
    public const DEFAULT_POLL_INTERVAL = 5;

    /**
     * One action by id. Raises NotFoundException when there is no such action.
     */
    public function get(int $id): Action
    {
        return $this->apiObject($this->path($id), 'action', Action::fromArray(...));
    }

    /**
     * One action by id, or null when there is no such action.
     */
    public function find(int $id): ?Action
    {
        return $this->apiFind($this->path($id), 'action', Action::fromArray(...));
    }

    /**
     * One page of the account's actions, newest first.
     *
     * @return Page<Action>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('actions', self::COLLECTION, Action::fromArray(...), $page, $perPage);
    }

    /**
     * Every action, a page at a time, as far as it is consumed.
     *
     * THIS IS THE WHOLE ACCOUNT'S HISTORY and it does not shrink. On an account that has been
     * running for a while it is a long walk, so take what you need and stop - the generator
     * stops requesting when you stop reading.
     *
     * @return \Generator<int, Action>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('actions', self::COLLECTION, Action::fromArray(...), $perPage);
    }

    /**
     * How many actions the account has on record, in one request that fetches none of them.
     */
    public function count(): int
    {
        return $this->apiCount('actions', self::COLLECTION);
    }

    /**
     * Answer the question a stalled action is asking.
     *
     * READ UserInteractionType BEFORE CALLING THIS. Both questions the API can ask are
     * decisions with consequences - `true` to an `allow-unclean-power-off` is a hard power
     * cut on a server that did not shut down cleanly, and `true` to a
     * `continue-after-ping-failure` accepts a server that was built and never answered.
     * Neither is a formality, and `proceed: false` is a real answer rather than a cancel.
     *
     * Answers with the action as it stands after the response, which may still be running.
     */
    public function proceed(Action|int $action, bool $proceed): Action
    {
        $id = self::idOf($action);

        return Action::fromArray(
            $this->apiPost($this->path($id) . '/proceed', ['proceed' => $proceed])->requireObject('action')
        );
    }

    /**
     * Fetch the action repeatedly until it finishes, and raise if it did not finish well.
     *
     * The loop every caller of this API needs and nobody should write twice. It returns the
     * completed action, and raises rather than returning for all four ways that does not
     * happen:
     *
     *  - ActionFailedException - the action errored.
     *  - ActionBlockedException - it is waiting on an answer, or on an unpaid invoice.
     *    Neither resolves by waiting; see the exception.
     *  - ActionTimedOutException - `$timeout` was reached. NOTHING WAS CANCELLED.
     *  - MalformedResponseException - the action's status cannot be classified, so waiting
     *    longer would only postpone saying so. Added in 0.2.0; 0.1.0 treated such an action as
     *    still running and polled it until it timed out.
     *  - whatever the request itself raised, untouched.
     *
     * THE FIRST CHECK HAPPENS BEFORE THE FIRST SLEEP. An action that is already finished -
     * which a fast one often is by the time the mutation's response has been parsed - costs
     * one request and no waiting.
     *
     * @param  Action|int  $action  the action, or its id. Passing the action already fetched
     *                              does not save a request: it is re-fetched, because the
     *                              copy in hand is a snapshot from before the wait began
     * @param  int|null  $timeout  seconds to wait before giving up. Null uses
     *                             DEFAULT_TIMEOUT; 0 means check once and do not wait
     * @param  int|null  $interval  seconds between polls. Null uses DEFAULT_POLL_INTERVAL
     * @param  callable(Action): void|null  $onPoll  called with every action fetched,
     *                                               including the last. For a progress
     *                                               display - see ActionProgress
     * @param  callable(int): void|null  $wait  how to wait, given a number of seconds.
     *                                          Defaults to sleep(). The seam for a test that
     *                                          must not take ten minutes, and for an
     *                                          application whose event loop has its own idea
     *                                          of what waiting means
     */
    public function await(
        Action|int $action,
        ?int $timeout = null,
        ?int $interval = null,
        ?callable $onPoll = null,
        ?callable $wait = null,
    ): Action {
        $id = self::idOf($action);
        $timeout ??= self::DEFAULT_TIMEOUT;
        $interval ??= self::DEFAULT_POLL_INTERVAL;

        if ($timeout < 0) {
            throw new InvalidArgumentException('A timeout cannot be negative; 0 means check once without waiting.');
        }

        if ($interval < 1) {
            throw new InvalidArgumentException(
                'A polling interval of less than a second would spend requests without learning '
                    . 'anything; BinaryLane actions do not move that fast.'
            );
        }

        $wait ??= static function (int $seconds): void {
            sleep($seconds);
        };

        $waited = 0;

        while (true) {
            $current = $this->get($id);

            if ($onPoll !== null) {
                $onPoll($current);
            }

            // A status this package cannot classify is neither running nor finished, so
            // nothing can be concluded from it - and treating it as running polls it to the
            // deadline and then reports a timeout of an action that may never have existed.
            // With the envelope check in place a malformed response is caught before it gets
            // here; what remains is a status the API has added since this release.
            if ($current->status === null) {
                $this->logger->error('BinaryLane action has an unclassifiable status', [
                    'action' => $current->id,
                    'status' => $current->raw['status'] ?? null,
                ]);

                throw MalformedResponseException::unusableActionStatus(
                    $current->id,
                    $current->raw['status'] ?? null
                );
            }

            if ($current->hasFailed()) {
                $this->logger->error('BinaryLane action failed', [
                    'action' => $current->id,
                    'type' => $current->type,
                    'reason' => $current->reason,
                ]);

                throw ActionFailedException::for($current);
            }

            if ($current->isSuccessful()) {
                return $current;
            }

            // Still in progress - but "in progress" covers two states that will never move.
            if ($current->isBlocked()) {
                $this->logger->warning('BinaryLane action is blocked', [
                    'action' => $current->id,
                    'type' => $current->type,
                    'interaction' => $current->userInteractionRequired?->interactionType?->value,
                    'blocking_invoice_id' => $current->blockingInvoiceId,
                ]);

                throw ActionBlockedException::for($current);
            }

            if ($waited >= $timeout) {
                throw ActionTimedOutException::for($current, $waited);
            }

            // Never sleep past the deadline: a 5 second interval against a 3 second budget
            // waits 3, so the timeout in the exception is the timeout that was asked for.
            $sleep = min($interval, $timeout - $waited);

            $wait($sleep);

            $waited += $sleep;
        }
    }

    /**
     * Wait for several actions, in the order given.
     *
     * SEQUENTIAL, NOT CONCURRENT, and the difference matters for the timeout: each action
     * gets the full `$timeout` of its own, so five actions can take five times as long as one.
     * That is deliberate - a shared budget would report a timeout against whichever action
     * happened to be last.
     *
     * The first failure raises, and the actions after it are not waited for. They have not
     * been cancelled either.
     *
     * @param  iterable<Action|int>  $actions
     * @param  callable(Action): void|null  $onPoll
     * @param  callable(int): void|null  $wait
     * @return list<Action>
     */
    public function awaitAll(
        iterable $actions,
        ?int $timeout = null,
        ?int $interval = null,
        ?callable $onPoll = null,
        ?callable $wait = null,
    ): array {
        $completed = [];

        foreach ($actions as $action) {
            $completed[] = $this->await($action, $timeout, $interval, $onPoll, $wait);
        }

        return $completed;
    }

    /**
     * The id of an action, whichever way it was given.
     */
    public static function idOf(Action|int $action): int
    {
        $id = $action instanceof Action ? $action->id : $action;

        if ($id < 1) {
            throw new InvalidArgumentException(sprintf(
                'An action id must be a positive integer; %d was given. An action built from an '
                    . 'empty response has an id of 0, which usually means a 202 was read as an action.',
                $id
            ));
        }

        return $id;
    }

    private function path(int $id): string
    {
        return 'actions/' . $id;
    }
}
