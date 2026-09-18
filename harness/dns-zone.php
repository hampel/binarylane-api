<?php

/**
 * Exercise: create a throwaway DNS zone, create it again, and delete it. THIS WRITES TO THE
 * REAL ACCOUNT - read the guards below before running it.
 *
 * Read-only by default. It needs BINARYLANE_ZONE_WRITE=1 to do anything at all, and in an
 * agent session that is ignored unless BINARYLANE_AGENT_MAY_WRITE_ZONE=1 is passed on the
 * command line as well.
 *
 * THE QUESTION. What does a second create of a zone that already exists answer? The
 * specification declares only a 400 on POST /v2/domains, and the answer decides what a caller
 * can do after a create that timed out: a create can land with its reply lost, so the obvious
 * move is to try again, and a retry that fails is only evidence of anything if a duplicate is
 * refused for a reason that says so. Three possible answers, each meaning something different:
 *
 *   - refused, with a message naming the duplicate: a retry is safe and its failure readable;
 *   - answered with the existing zone: create() is idempotent and a retry is harmless;
 *   - answered with a SECOND zone of the same name: a retry duplicates, and must never be blind.
 *
 * Also printed: how long each create took, since a timeout is where the question starts. The
 * first live run, on 2026-09-18, timed out after 30 seconds with no answer - and the zone had
 * been created. The second, waiting 120 seconds, got a 504 from BinaryLane's own gateway - and
 * the zone had been created again. So a create that gets no answer, or a 5xx, is re-read here
 * rather than treated as a failure, which is what RequestException tells every caller to do.
 *
 * ANSWERED on 2026-09-18, third run: the create took 60.9 s, got a 504, and landed; the
 * duplicate was refused in 31 ms with a 400 whose `name` error is "Domain name already in use.",
 * and one zone remained. Re-run it when a create's timing or a duplicate's answer might have
 * changed - Domains::create() and both exception classes repeat these figures.
 *
 * THE ZONE is `zz-delete-me-zone-<timestamp>.<BINARYLANE_DNS_ZONE>`, so it is unique, obvious in
 * the control panel if cleanup fails, and under a domain the account already holds rather than
 * one somebody else might own. It is created with no IP address, so BinaryLane adds only its
 * own default records. Every zone of that name is deleted in a `finally`, and the run exits
 * non-zero if one survives.
 *
 * Needs BINARYLANE_API_TOKEN and BINARYLANE_DNS_ZONE.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\Domain;
use Hampel\BinaryLane\Api\Exception\ApiException;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;
use Hampel\BinaryLane\Api\Exception\RequestException;
use Hampel\BinaryLane\Api\Exception\ServerException;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · dns zone');

$parent = getenv('BINARYLANE_DNS_ZONE');

if ($parent === false || $parent === '') {
    $io->error('BINARYLANE_DNS_ZONE is not set.');
    $io->line('Name a zone the account holds. The probe zone is created as a name beneath it.');

    exit(1);
}

$probe = harness_probe_name('zone') . '.' . strtolower(rtrim($parent, '.'));

$io->info('probe zone: ' . $probe);
$io->line();

$live = harness_mode(
    $io,
    'BINARYLANE_ZONE_WRITE',
    'BINARYLANE_AGENT_MAY_WRITE_ZONE',
    sprintf('the zone %s will be created twice and deleted', $probe)
);

$binarylane = harness_client($io, 120);
$domains = $binarylane->domains();

/**
 * Every zone on the account with exactly this name - one, normally; two if a duplicate create
 * made a second.
 *
 * @return list<Domain>
 */
$named = static function (string $name) use ($domains): array {
    $found = [];

    foreach ($domains->each() as $domain) {
        if (strcasecmp($domain->name, $name) === 0) {
            $found[] = $domain;
        }
    }

    return $found;
};

if (!$live) {
    try {
        $io->success(sprintf('the account is readable: %d zones', $domains->count()));
        $io->line();
        $io->warn('NOT PROVEN by this run: what a duplicate create answers. That needs a zone to');
        $io->warn('create twice.');
    } catch (ExceptionInterface $e) {
        $io->error($e::class);
        $io->error($e->getMessage());

        exit(1);
    }

    exit(0);
}

$failure = null;
$leaked = false;

try {
    $io->info('1. create the probe zone');

    $started = microtime(true);

    try {
        $first = $domains->create($probe);
    } catch (RequestException|ServerException $e) {
        $io->warn(sprintf(
            '%s after %d s - re-reading, as a caller should',
            $e instanceof ServerException ? 'HTTP ' . $e->statusCode : 'no answer',
            (int) round(microtime(true) - $started)
        ));

        $landed = $named($probe);

        if ($landed === []) {
            throw $e;
        }

        $io->success('THE CREATE LANDED - the zone exists although the request got no answer');
        $first = $landed[0];
    }

    $firstMs = (int) round((microtime(true) - $started) * 1000);

    $io->values([
        'id' => $first->id,
        'name' => $first->name,
        'took' => $firstMs . ' ms',
    ]);

    $io->line();
    $io->info('2. create it again');

    $started = microtime(true);

    try {
        $second = $domains->create($probe);
        $secondMs = (int) round((microtime(true) - $started) * 1000);

        $io->values([
            'answered' => 'success',
            'id' => $second->id,
            'took' => $secondMs . ' ms',
        ]);

        if ($second->id === $first->id) {
            $io->success('IDEMPOTENT - the duplicate was answered with the existing zone. A retried create is harmless.');
        } else {
            $io->error(sprintf('A SECOND ZONE - id %d against %d. A retried create duplicates.', $second->id, $first->id));
        }
    } catch (RequestException|ServerException $e) {
        $io->warn(sprintf(
            'the duplicate got %s after %d s - step 3 is the only evidence of what it did',
            $e instanceof ServerException ? 'HTTP ' . $e->statusCode : 'no answer',
            (int) round(microtime(true) - $started)
        ));
    } catch (ApiException $e) {
        $secondMs = (int) round((microtime(true) - $started) * 1000);

        $io->values([
            'answered' => $e::class,
            'status' => $e->statusCode,
            'messages' => implode(' | ', $e->messages()) ?: '(none)',
            'body' => $e->body !== '' ? $e->body : '(empty)',
            'took' => $secondMs . ' ms',
        ]);

        $io->success('REFUSED - read the messages above for whether they say the zone already exists.');
    }

    $io->line();
    $io->info('3. how many zones of that name exist now?');

    $io->values(['zones named ' . $probe => count($named($probe))]);
} catch (ExceptionInterface $e) {
    $failure = $e;

    $io->line();
    $io->error($e::class);
    $io->error($e->getMessage());
} finally {
    $io->line();

    // The name is checked before every delete, because Domains::delete() takes a NAME and
    // removes the zone and every record in it. Only the generated probe name may reach it - a
    // mistake here that passed BINARYLANE_DNS_ZONE would delete the real zone.
    if (!str_starts_with($probe, 'zz-delete-me-zone-')) {
        throw new LogicException('refusing to delete a zone that is not a probe: ' . $probe);
    }

    try {
        $deleted = 0;

        // A duplicate create may have left two zones of this name, and a delete by name removes
        // one - so delete until none is left, with a ceiling in case a delete does nothing.
        for ($attempt = 0; $attempt < 3 && $named($probe) !== []; $attempt++) {
            $domains->delete($probe);
            $deleted++;
        }

        $left = count($named($probe));

        if ($left === 0) {
            $io->success(sprintf('cleaned up - %s deleted (%d delete%s)', $probe, $deleted, $deleted === 1 ? '' : 's'));
        } else {
            $leaked = true;

            $io->error(sprintf('✗ CLEANUP INCOMPLETE - %d zone%s named %s remain. Remove by hand.', $left, $left === 1 ? '' : 's', $probe));
        }
    } catch (ExceptionInterface $cleanup) {
        $leaked = true;

        $io->error('✗ CLEANUP FAILED - ' . $cleanup::class);
        $io->error($cleanup->getMessage());
        $io->error(sprintf('Remove the zone %s by hand.', $probe));
    }
}

// Outside the try, because PHP does not run a `finally` on exit() - an exit inside the block
// would skip the cleanup on exactly the runs that succeeded.
if ($failure !== null || $leaked) {
    exit(1);
}
