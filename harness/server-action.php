<?php

/**
 * Exercise: perform a real server action and wait for it. THIS ACTS ON A REAL SERVER.
 *
 * Read-only by default. It needs BINARYLANE_RUN_ACTION=1 to do anything, and in an agent
 * session that is ignored unless BINARYLANE_AGENT_MAY_RUN_ACTION=1 is passed on the command
 * line as well.
 *
 * THE ACTION IS DELIBERATELY A HARMLESS ONE. `uptime` asks the server how long it has been
 * up and changes nothing - it is in this harness because it is the only way to exercise the
 * action machinery without a side effect worth worrying about. Nothing here powers anything
 * off, and the destructive actions are not reachable from this file at all: that is not an
 * oversight, it is the design. To exercise a rebuild, write the call by hand and mean it.
 *
 * THE QUESTIONS, and they are the biggest open ones in the package:
 *
 *   1. Does a server action answer 200 with the action, or 202 with nothing? The
 *      specification declares BOTH on every one of the forty-two, which is why every method
 *      in ServerActions returns `?Action` and why null has to mean something. If the API only
 *      ever answers 200, that nullable return is defensive and can be documented as such; if
 *      it really does answer 202, callers need to know when.
 *   2. Does `result_data` carry the answer to a question-shaped action, and in what form? It
 *      is typed as a string whatever the action, so "14 days" and "1209600" are both possible
 *      and the package cannot say which.
 *   3. How long does an action of this kind take, and is `progress` populated while it runs?
 *      The polling defaults in Actions were chosen without a measurement.
 *
 * Needs BINARYLANE_API_TOKEN and BINARYLANE_SERVER_ID.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · server action');

$serverId = (int) (getenv('BINARYLANE_SERVER_ID') ?: 0);

if ($serverId < 1) {
    $io->error('BINARYLANE_SERVER_ID is not set to a server id.');

    exit(1);
}

$binarylane = harness_client($io);

try {
    $server = $binarylane->servers()->get($serverId);
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}

$io->info($server->describe());
$io->line();

$live = harness_mode(
    $io,
    'BINARYLANE_RUN_ACTION',
    'BINARYLANE_AGENT_MAY_RUN_ACTION',
    sprintf('an "uptime" action will be performed on server %d', $serverId)
);

if (!$live) {
    $io->values([
        'actionable' => $server->isActionable() ? 'yes' : 'NO - under maintenance or still building',
        'status' => $server->status?->value ?? '(unrecognised)',
        'recent actions' => iterator_count($binarylane->servers()->eachAction($serverId, 20)),
    ]);

    $io->line();
    $io->warn('NOT PROVEN by this run: whether an action answers 200 or 202, what result_data');
    $io->warn('carries, or how long one takes. All three need an action to actually be performed.');

    exit(0);
}

if (!$server->isActionable()) {
    $io->error('the server is not in a state that accepts actions - see `actionable` above');

    exit(1);
}

try {
    $io->info('1. performing the action');

    $started = microtime(true);
    $action = $binarylane->serverActions()->uptime($serverId);

    if ($action === null) {
        $io->warn('the API answered 202 with no body, so there is no action to wait on.');
        $io->warn('That is the case ServerActions returns null for, and this run has just');
        $io->warn('confirmed it happens. The action list is where to look for what it started:');

        foreach ($binarylane->servers()->actions($serverId, perPage: 3) as $recent) {
            $io->line('  ' . $recent->describe());
        }

        exit(0);
    }

    $io->success('the API answered 200 with the action');
    $io->values([
        'id' => $action->id,
        'type' => $action->type,
        'status' => $action->status?->value ?? '(unrecognised)',
        'resource' => sprintf('%s %s', $action->resourceType?->value ?? '?', $action->resourceId ?? '?'),
    ]);

    $io->line();
    $io->info('2. waiting for it');

    $polls = 0;

    $completed = $binarylane->actions()->await(
        $action,
        timeout: 120,
        interval: 2,
        onPoll: function (Action $polled) use ($io, &$polls): void {
            $polls++;

            $io->line(sprintf(
                '   poll %d: %s, %d%%%s',
                $polls,
                $polled->status?->value ?? '?',
                $polled->progress?->percentComplete ?? 0,
                $polled->progress?->describe() !== '' ? ' - ' . $polled->progress?->describe() : ''
            ));
        }
    );

    $elapsed = microtime(true) - $started;

    $io->line();
    $io->success(sprintf('completed after %.1fs and %d poll(s)', $elapsed, $polls));

    $io->values([
        'result data' => $completed->resultData ?? '(none)',
        'started at' => $completed->startedAt?->format(DATE_ATOM) ?? '(not sent)',
        'completed at' => $completed->completedAt?->format(DATE_ATOM) ?? '(not sent)',
        'reason' => $completed->reason,
    ]);

    if ($completed->resultData === null) {
        $io->line();
        $io->warn('no result_data. Either this action does not report one, or the field has moved -');
        $io->warn('the package tells callers that a question-shaped action answers there.');
    }

    if ($polls === 1) {
        $io->line();
        $io->info('it was already finished on the first poll, so the polling interval was never');
        $io->info('exercised. A longer action would be needed to measure it.');
    }
} catch (ActionFailedException $e) {
    $io->error('the action failed: ' . $e->action->reason);

    exit(1);
} catch (ActionBlockedException $e) {
    $io->error($e->getMessage());
    $io->warn('this is the state a naive polling loop waits out forever');

    exit(1);
} catch (ActionTimedOutException $e) {
    $io->error($e->getMessage());
    $io->warn(sprintf('it reached %d%% - nothing was cancelled', $e->action->progress?->percentComplete ?? 0));

    exit(1);
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}
