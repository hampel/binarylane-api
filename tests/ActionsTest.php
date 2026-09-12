<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Enum\ActionStatus;
use Hampel\BinaryLane\Api\Enum\ResourceType;
use Hampel\BinaryLane\Api\Enum\UserInteractionType;
use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;

final class ActionsTest extends TestCase
{
    /**
     * Every wait in this file goes through here, so no test sleeps. The seconds asked for are
     * recorded, because "did it respect the interval" and "did it stop at the timeout" are
     * assertions about exactly that list.
     *
     * @param  list<int>  $waited
     */
    private function recordingWait(array &$waited): callable
    {
        return static function (int $seconds) use (&$waited): void {
            $waited[] = $seconds;
        };
    }

    public function testItFetchesAnActionById(): void
    {
        $this->client->pushJson(200, $this->action(42));

        $action = $this->binarylane()->actions()->get(42);

        $this->assertSame('/v2/actions/42', $this->sentPath());
        $this->assertSame(42, $action->id);
        $this->assertSame(ActionStatus::Completed, $action->status);
        $this->assertSame('power_on', $action->type);
        $this->assertSame(ResourceType::Server, $action->resourceType);
        $this->assertSame(1234, $action->resourceId);
        $this->assertTrue($action->isSuccessful());
    }

    public function testItReadsTimestampsAsUtc(): void
    {
        $this->client->pushJson(200, $this->action(42));

        $action = $this->binarylane()->actions()->get(42);

        $this->assertNotNull($action->startedAt);
        $this->assertSame('2026-09-12 01:00:00', $action->startedAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $action->startedAt->getTimezone()->getName());
        $this->assertSame('2026-09-12 01:00:30', $action->completedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * BinaryLane documents its timestamps as "ISO8601" without an example, so a value with no
     * offset has to be read as UTC rather than in whatever timezone the box happens to use.
     */
    public function testATimestampWithNoOffsetIsReadAsUtcRatherThanLocalTime(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Australia/Sydney');

        try {
            $this->client->pushJson(200, $this->action(1, 'completed', ['started_at' => '2026-09-12T01:00:00']));

            $action = $this->binarylane()->actions()->get(1);

            $this->assertSame('2026-09-12T01:00:00+00:00', $action->startedAt?->format(\DateTimeInterface::ATOM));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testFindAnswersNullForAnActionThatIsNotThere(): void
    {
        $this->client->pushJson(404, $this->problem('Not Found'));

        $this->assertNull($this->binarylane()->actions()->find(999));
    }

    public function testGetStillRaisesForAnActionThatIsNotThere(): void
    {
        $this->client->pushJson(404, $this->problem('Not Found'));

        $this->expectException(NotFoundException::class);

        $this->binarylane()->actions()->get(999);
    }

    public function testItListsAPageOfActions(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['id' => 1, 'status' => 'completed', 'type' => 'power_on'],
            ['id' => 2, 'status' => 'in-progress', 'type' => 'rebuild'],
        ], 'actions', total: 57));

        $page = $this->binarylane()->actions()->list();

        $this->assertCount(2, $page);
        $this->assertSame(57, $page->total);
        $this->assertSame(1, $page->currentPage);
        $this->assertFalse($page->hasMore());
        $this->assertSame('page=1', $this->sentQuery());
    }

    public function testCountAsksForNoItemsAtAll(): void
    {
        $this->client->pushJson(200, ['actions' => [], 'meta' => ['total' => 57]]);

        $this->assertSame(57, $this->binarylane()->actions()->count());
        $this->assertSame('per_page=0', $this->sentQuery());
    }

    public function testEachFollowsTheNextLinkRatherThanIncrementingAPage(): void
    {
        $this->client
            ->pushJson(200, $this->collection(
                [['id' => 1, 'status' => 'completed']],
                'actions',
                total: 2,
                next: 'https://api.binarylane.com.au/v2/actions?page=2&per_page=1'
            ))
            ->pushJson(200, $this->collection([['id' => 2, 'status' => 'completed']], 'actions', total: 2));

        $ids = [];

        foreach ($this->binarylane()->actions()->each() as $action) {
            $ids[] = $action->id;
        }

        $this->assertSame([1, 2], $ids);
        $this->assertCount(2, $this->client->requests);
        $this->assertSame('page=2&per_page=1', $this->sentQuery());
    }

    /**
     * The generator only requests what is read, so abandoning a walk stops the requests.
     */
    public function testEachStopsRequestingWhenTheCallerStopsReading(): void
    {
        $this->client->pushJson(200, $this->collection(
            [['id' => 1], ['id' => 2]],
            'actions',
            total: 100,
            next: 'https://api.binarylane.com.au/v2/actions?page=2'
        ));

        foreach ($this->binarylane()->actions()->each() as $action) {
            $this->assertSame(1, $action->id);

            break;
        }

        $this->assertCount(1, $this->client->requests);
    }

    public function testAwaitReturnsImmediatelyForAnActionThatHasAlreadyFinished(): void
    {
        $this->client->pushJson(200, $this->action(7, 'completed'));

        $waited = [];
        $action = $this->binarylane()->actions()->await(7, wait: $this->recordingWait($waited));

        $this->assertTrue($action->isSuccessful());
        $this->assertSame([], $waited, 'a finished action should not be waited on');
        $this->assertCount(1, $this->client->requests);
    }

    public function testAwaitPollsUntilTheActionCompletes(): void
    {
        $this->client
            ->pushJson(200, $this->action(7, 'in-progress'))
            ->pushJson(200, $this->action(7, 'in-progress'))
            ->pushJson(200, $this->action(7, 'completed'));

        $waited = [];
        $seen = [];

        $action = $this->binarylane()->actions()->await(
            7,
            interval: 2,
            onPoll: static function (Action $polled) use (&$seen): void {
                $seen[] = $polled->status?->value;
            },
            wait: $this->recordingWait($waited)
        );

        $this->assertTrue($action->isSuccessful());
        $this->assertSame([2, 2], $waited);
        $this->assertSame(['in-progress', 'in-progress', 'completed'], $seen);
    }

    public function testAwaitAcceptsTheActionItselfRatherThanAnId(): void
    {
        $this->client->pushJson(200, $this->action(7, 'completed'));

        $action = Action::fromArray(['id' => 7, 'status' => 'in-progress']);

        $this->assertTrue($this->binarylane()->actions()->await($action)->isSuccessful());
        $this->assertSame('/v2/actions/7', $this->sentPath());
    }

    public function testAwaitRaisesWhenTheActionErrored(): void
    {
        $this->client->pushJson(200, $this->action(7, 'errored', ['reason' => 'The image is not licensed for this account.']));

        try {
            $this->binarylane()->actions()->await(7);

            $this->fail('expected an ActionFailedException');
        } catch (ActionFailedException $e) {
            $this->assertSame(7, $e->action->id);
            $this->assertStringContainsString('The image is not licensed for this account.', $e->getMessage());
        }
    }

    /**
     * The action is still reporting `in-progress`, so a loop that only watched the status
     * would wait for the full timeout and then report the wrong thing.
     */
    public function testAwaitRaisesRatherThanWaitingOnAnActionThatIsAskingAQuestion(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress', [
            'user_interaction_required' => ['interaction_type' => 'allow-unclean-power-off'],
        ]));

        $waited = [];

        try {
            $this->binarylane()->actions()->await(7, wait: $this->recordingWait($waited));

            $this->fail('expected an ActionBlockedException');
        } catch (ActionBlockedException $e) {
            $this->assertSame(
                UserInteractionType::AllowUncleanPowerOff,
                $e->action->userInteractionRequired?->interactionType
            );
            $this->assertStringContainsString('Actions::proceed()', $e->getMessage());
            $this->assertSame([], $waited);
        }
    }

    public function testAwaitRaisesForAnActionHeldUpByAnUnpaidInvoice(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress', ['blocking_invoice_id' => 9182]));

        try {
            $this->binarylane()->actions()->await(7);

            $this->fail('expected an ActionBlockedException');
        } catch (ActionBlockedException $e) {
            $this->assertSame(9182, $e->action->blockingInvoiceId);
            $this->assertStringContainsString('blocked by invoice 9182', $e->getMessage());
        }
    }

    public function testAwaitTimesOutWithoutCancellingAnything(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->client->pushJson(200, $this->action(7, 'in-progress'));
        }

        $waited = [];

        try {
            $this->binarylane()->actions()->await(7, timeout: 10, interval: 4, wait: $this->recordingWait($waited));

            $this->fail('expected an ActionTimedOutException');
        } catch (ActionTimedOutException $e) {
            $this->assertSame(7, $e->action->id);
            $this->assertStringContainsString('has not been cancelled', $e->getMessage());
            // Never sleeps past the deadline, so the last wait is trimmed to what is left.
            $this->assertSame([4, 4, 2], $waited);
        }
    }

    public function testATimeoutOfZeroChecksOnceAndDoesNotWait(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress'));

        $waited = [];

        $this->expectException(ActionTimedOutException::class);

        try {
            $this->binarylane()->actions()->await(7, timeout: 0, wait: $this->recordingWait($waited));
        } finally {
            $this->assertCount(1, $this->client->requests);
            $this->assertSame([], $waited);
        }
    }

    public function testAwaitRefusesAnIntervalThatWouldSpendRequestsForNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->actions()->await(7, interval: 0);
    }

    public function testAwaitRefusesANegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->actions()->await(7, timeout: -1);
    }

    /**
     * An action built from a 202 that carried no body has an id of 0, and waiting on it would
     * request `/v2/actions/0`.
     */
    public function testAwaitRefusesAnActionWithNoId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');

        $this->binarylane()->actions()->await(Action::fromArray([]));
    }

    public function testAwaitAllWaitsForEachInTurn(): void
    {
        $this->client
            ->pushJson(200, $this->action(1, 'completed'))
            ->pushJson(200, $this->action(2, 'completed'));

        $waited = [];
        $completed = $this->binarylane()->actions()->awaitAll([1, 2], wait: $this->recordingWait($waited));

        $this->assertCount(2, $completed);
        $this->assertSame([1, 2], array_map(static fn (Action $a): int => $a->id, $completed));
    }

    public function testProceedAnswersTheQuestion(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress'));

        $this->binarylane()->actions()->proceed(7, true);

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/actions/7/proceed', $this->sentPath());
        $this->assertSame(['proceed' => true], $this->sentBody());
    }

    public function testProceedCanRefuse(): void
    {
        $this->client->pushJson(200, $this->action(7, 'errored'));

        $this->binarylane()->actions()->proceed(Action::fromArray(['id' => 7]), false);

        $this->assertSame(['proceed' => false], $this->sentBody());
    }
}
