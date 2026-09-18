<?php

/**
 * Not an exercise. Discovery is glob('harness/*.php'), top level only, so a file down here
 * is required by the exercises that need it rather than listed as one of them.
 *
 * This carries the third of the three layers that keep an accident out of this harness:
 *
 *   1. rig itself does not load the package's .env when CLAUDECODE is set. Nothing is
 *      needed from the package for that one, which is what makes it the layer that
 *      protects a harness whose author never thought about any of this.
 *   2. each exercise defaults to the harmless thing - a read - and any real effect is
 *      opt-in.
 *   3. this: the opt-in itself is refused under an agent.
 *
 * Two and three look redundant and are not. The opt-in in (2) lives in the .env of whoever
 * owns the credentials, and it generally says yes, because that is how they run their own
 * exercises - so (2)'s default never applies to the file that actually exists. An agent
 * inherits that authorisation without having made the decision.
 *
 * WHY THIS MATTERS MORE HERE THAN IN MOST PACKAGES. A BinaryLane API token is not scoped:
 * there is one kind of credential and it can cancel a server. There is no read-only token
 * to hand a harness, so the separation has to be structural rather than in the credential.
 */

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\BinaryLane\Api\Client;
use Hampel\Rig\Io;

/**
 * Whether a real effect must be refused: an agent is running, and has not been told that
 * this once it may.
 *
 * CLAUDECODE is a fact about who is running the command, which is the one thing a stale
 * .env cannot fake. The variable named here is the deliberate act, and belongs on the
 * command line and never in .env - a persisted one would recreate the very problem.
 *
 * Both reads fail safe. An absent or renamed CLAUDECODE falls back to the ordinary opt-in -
 * which is still the harmless default unless asked - rather than to "assume human,
 * proceed", so a rename upstream costs this layer and not the safety. And the override is
 * exactly '1': an environment variable is always a string, so a loose test would make =0
 * mean yes.
 */
function harness_agent_refuses(string $variable): bool
{
    return getenv('CLAUDECODE') !== false && getenv($variable) !== '1';
}

/**
 * A client built from BINARYLANE_API_TOKEN, or an explanation and an exit.
 *
 * The token check is deliberately not a guard against anything: a missing token is the rig
 * withholding the .env, which is layer 1 doing its job. Say so, rather than leaving someone
 * to conclude the package is broken.
 */
function harness_client(Io $io, int $timeout = 30): Client
{
    $token = getenv('BINARYLANE_API_TOKEN');

    if ($token === false || $token === '') {
        $io->error('BINARYLANE_API_TOKEN is not set.');
        $io->line();
        $io->line('If you are a person: copy .env.example to .env beside the package and put a');
        $io->line('token in it. If you are an agent: this is the guard working. Do not go looking');
        $io->line('for the token, and do not edit .env - ask.');

        exit(1);
    }

    $factory = new HttpFactory();

    return Client::withToken($token, new Guzzle(['timeout' => $timeout]), $factory, $factory);
}

/**
 * Print the mode ABOVE the work, and answer whether the real effect may happen.
 *
 * Above, not after, because a run that ends "nothing was changed" has already been read as
 * a pass by the time anyone gets there. A sink run also says what it did NOT prove - silence
 * about that is what invites the next person to read it as a full run.
 *
 * @param  string  $optIn  the ordinary opt-in variable, which lives in .env
 * @param  string  $override  the agent override, which never does
 */
function harness_mode(Io $io, string $optIn, string $override, string $effect): bool
{
    if (getenv($optIn) !== '1') {
        $io->warn('mode: read-only - nothing will be changed');
        $io->line(sprintf('      what is skipped: %s', $effect));
        $io->line(sprintf('      set %s=1 to do it for real', $optIn));
        $io->line();

        return false;
    }

    if (harness_agent_refuses($override)) {
        $io->warn(sprintf('mode: read-only - %s is set, and is ignored in an agent session', $optIn));
        $io->line(sprintf('      what is skipped: %s', $effect));
        $io->line(sprintf('      %s=1 on the command line unlocks it for one run', $override));
        $io->line();

        return false;
    }

    $io->warn(sprintf('mode: LIVE against the real account - %s', $effect));
    $io->line();

    return true;
}

/**
 * A name for a throwaway record, chosen so a failed cleanup is obvious in BinaryLane's own
 * control panel rather than looking like something somebody meant.
 */
function harness_probe_name(string $what): string
{
    return sprintf('zz-delete-me-%s-%s', $what, date('Ymd-His'));
}
