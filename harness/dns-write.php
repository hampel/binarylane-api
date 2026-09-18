<?php

/**
 * Exercise: create a throwaway TXT record, probe the write semantics against it, and delete
 * it again. THIS WRITES TO A REAL DNS ZONE - read the guards below before running it.
 *
 * Read-only by default. It needs BINARYLANE_DNS_WRITE=1 to do anything at all, and in an
 * agent session that is ignored unless BINARYLANE_AGENT_MAY_WRITE_DNS=1 is passed on the
 * command line as well.
 *
 * THE QUESTIONS. Three claims in this package come from prose in the specification rather
 * than from anything observable, and each one changes what a caller should write:
 *
 *   1. "The default and only supported value is 3600" - is a TTL really forced, and is a
 *      different one refused or silently replaced? The package sends no TTL at all on the
 *      strength of this.
 *   2. "Any values not provided will be retained. Provide empty strings to clear existing
 *      string values, nulls to retain" - on a PUT. That is backwards from the usual rule and
 *      from this API's own create, and update() passes an array through unfiltered because
 *      of it.
 *   3. Is the apex really `@`? The package turns an empty name into `@` rather than sending
 *      it, which is right if BinaryLane writes names the way BIND does and wrong otherwise.
 *   4. Which record types need a trailing dot on their target? An MX without one is refused -
 *      "data must end with a '.' for MX records" - and the package adds it. Whether CNAME, NS
 *      and SRV behave the same, accept the dotless form as fully qualified, or accept it and
 *      read it RELATIVE to the zone decides whether the package should add it there too. The
 *      last is the dangerous one, because it answers 200: so the zone file is read back as
 *      well, which is where a relative reading shows.
 *
 *      ANSWERED on 2026-09-18: MX is refused; SRV is accepted and read relative to the zone
 *      (`sip.example.com` served as `sip.example.com.<zone>.`); CNAME and NS are read as fully
 *      qualified. The package adds the dot for MX and SRV.
 *
 * A mocked suite answers all three the way the mock was written, which is the same way the
 * code was written, so they agree with each other and possibly with nothing else.
 *
 * THE RECORD is a TXT under a name beginning `zz-delete-me-`, so a failed cleanup is obvious
 * in the control panel rather than looking like something somebody meant. It is deleted in a
 * `finally`, and the run exits non-zero if the deletion did not happen - a run that left
 * litter behind is not a pass.
 *
 * Needs BINARYLANE_API_TOKEN and BINARYLANE_DNS_ZONE.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\ApiException;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;
use Hampel\BinaryLane\Api\Support\Cast;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · dns write');

$zone = getenv('BINARYLANE_DNS_ZONE');

if ($zone === false || $zone === '') {
    $io->error('BINARYLANE_DNS_ZONE is not set.');
    $io->line('Name the zone to write the throwaway record into. It must be one the account holds.');

    exit(1);
}

$io->info('zone: ' . $zone);
$io->line();

$live = harness_mode(
    $io,
    'BINARYLANE_DNS_WRITE',
    'BINARYLANE_AGENT_MAY_WRITE_DNS',
    sprintf('a record will be created in %s and deleted again', $zone)
);

$binarylane = harness_client($io);
$records = $binarylane->domains()->records($zone);

if (!$live) {
    try {
        $existing = $records->all();

        $io->success(sprintf('the zone is readable: %d records', count($existing)));
        $io->line();
        $io->warn('NOT PROVEN by this run: whether the TTL is forced to 3600, how a PUT treats a');
        $io->warn('field it was not given, and whether the apex is written as "@". All three need');
        $io->warn('a record that does not exist yet.');
    } catch (ExceptionInterface $e) {
        $io->error($e::class);
        $io->error($e->getMessage());

        exit(1);
    }

    exit(0);
}

$name = harness_probe_name('txt');
$created = null;
/** @var list<int> $extraIds records created by step 6, deleted in the finally */
$extraIds = [];
$failure = null;
$leaked = false;

try {
    $io->info('1. create - with no TTL, because the package sends none');

    $created = $records->create(DomainRecord::txt($name, 'first value'));

    $io->values([
        'id' => $created->id ?? '(none)',
        'name' => $created->name,
        'data' => $created->data ?? '(none)',
        'ttl' => $created->ttl ?? '(not returned)',
    ]);

    if ($created->ttl === DomainRecord::TTL) {
        $io->success('the TTL came back as 3600, as the specification says it must');
    } elseif ($created->ttl === null) {
        $io->warn('no TTL came back at all - the read model expects one on every record');
    } else {
        $io->warn(sprintf('the TTL came back as %d, not 3600. The claim in DomainRecord is wrong.', $created->ttl));
    }

    $id = $created->id;

    if ($id === null) {
        throw new RuntimeException('the created record came back without an id, so nothing else can run');
    }

    $io->line();
    $io->info('2. can a different TTL be set? The package says no and never sends one.');

    try {
        $records->update($id, ['ttl' => 300]);

        $after = $records->get($id);

        if ($after->ttl === 300) {
            $io->error('IT WAS ACCEPTED AND APPLIED. The "3600 only" claim is wrong, and the package');
            $io->error('is dropping a TTL callers could have set.');
        } else {
            $io->success(sprintf(
                'accepted and ignored - the record still reads %d. Silently, which is why the '
                    . 'package does not offer the field.',
                $after->ttl ?? 0
            ));
        }
    } catch (ExceptionInterface $e) {
        $io->success('refused outright: ' . $e::class);
        $io->line('  ' . $e->getMessage());
    }

    $io->line();
    $io->info('3. does a PUT retain what it is not given?');

    $records->update($id, ['data' => 'second value']);

    $after = $records->get($id);

    $io->values([
        'name after' => $after->name,
        'data after' => $after->data ?? '(none)',
    ]);

    if ($after->name === $name && $after->data === 'second value') {
        $io->success('the name survived a PUT that did not mention it - retention confirmed');
    } else {
        $io->error('the name did NOT survive. update() must send every field, and its docblock is wrong.');
    }

    $io->line();
    $io->info('4. is the ?type= filter honoured? Only answerable while this record exists.');

    // Both zones on the account hold nothing but A records, so a type filter cannot be told
    // from no filter by reading alone - asking for type=A returns everything either way. The
    // probe record above is a TXT, so for as long as it is there the zone has two types and
    // the question has an answer. This is the only moment it does.
    $txt = $records->all(type: DomainRecordType::TXT);
    $everything = $records->all();

    $io->values([
        'records in the zone' => count($everything),
        'records of type TXT' => count($txt),
    ]);

    if (count($everything) === count($txt)) {
        $io->error('IGNORED - asking for TXT returned every record in the zone.');
        $io->error('DomainRecords::upsert() cannot be trusted against this API until that is understood.');
    } elseif (count($txt) >= 1) {
        $io->success('the type filter is honoured');
    } else {
        $io->warn('the filter returned nothing at all, including the record just created');
    }

    $io->line();
    $io->info('5. is the apex written as "@"?');

    $apex = $records->all(type: DomainRecordType::NS);
    $apexNames = array_values(array_unique(array_map(
        static fn (DomainRecord $record): string => $record->name,
        $apex
    )));

    $io->values([
        'NS record names' => implode(', ', $apexNames) ?: '(no NS records visible)',
    ]);

    if (in_array('@', $apexNames, true)) {
        $io->success('"@" is how this API writes the apex, as DomainRecord assumes');
    } elseif (in_array('', $apexNames, true)) {
        $io->error('the apex comes back as an EMPTY STRING, not "@". DomainRecord::name() is converting');
        $io->error('empty names to "@" and would be writing them to the wrong place.');
    } else {
        $io->warn('no apex record was visible, so this one is unanswered');
    }

    $io->line();
    $io->info('6. which record types need a trailing dot on the target?');

    // Posted RAW, through connection(), not through DomainRecord: toArray() adds the dot to an
    // MX target, so the package could never send the case being asked about. The status and
    // the stored value are the answer.
    $path = 'domains/' . rawurlencode($zone) . '/records';
    $targets = [
        'CNAME' => ['data' => 'www.example.com'],
        'MX' => ['data' => 'mail.example.com', 'priority' => 10],
        'NS' => ['data' => 'ns1.example.com'],
        'SRV' => ['data' => 'sip.example.com', 'priority' => 10, 'weight' => 5, 'port' => 5060],
    ];
    $probeNames = [];

    foreach ($targets as $type => $fields) {
        $outcome = [];

        foreach (['without dot' => '', 'with dot' => '.'] as $label => $suffix) {
            $recordName = harness_probe_name(strtolower($type)) . ($suffix === '' ? '-nodot' : '-dot');

            if ($type === 'SRV') {
                $recordName = '_sip._tcp.' . $recordName;
            }

            try {
                $row = $binarylane->connection()
                    ->post($path, ['type' => $type, 'name' => $recordName] + ['data' => $fields['data'] . $suffix] + $fields)
                    ->requireObject('domain_record');

                $id = Cast::int($row['id'] ?? null);

                if ($id !== null) {
                    $extraIds[] = $id;
                }

                $probeNames[] = $recordName;
                $outcome[$type . ' ' . $label] = sprintf('accepted, stored "%s"', Cast::string($row['data'] ?? null) ?? '(none)');
            } catch (ApiException $e) {
                $outcome[$type . ' ' . $label] = sprintf('REFUSED %d: %s', $e->statusCode, implode(' | ', $e->messages()) ?: $e->getMessage());
            }
        }

        $io->values($outcome);
    }

    $io->line();
    $io->info('   how the zone file renders each one that was accepted:');

    $zoneFile = $binarylane->domains()->get($zone)->zoneFile;
    $lines = array_values(array_filter(
        explode("\n", $zoneFile),
        static function (string $line) use ($probeNames): bool {
            foreach ($probeNames as $probeName) {
                if (str_contains($line, $probeName)) {
                    return true;
                }
            }

            return false;
        }
    ));

    foreach ($lines as $line) {
        $io->line('   ' . trim($line));
    }

    if ($lines === []) {
        $io->warn('none of the probe records appear in the zone file - read the list above instead');
    }

    $io->line();
    $io->warn('Read the zone file lines: a target shown as www.example.com.' . $zone . '. was read');
    $io->warn('relative to the zone, and the package must add the dot for that type.');
} catch (ExceptionInterface|RuntimeException $e) {
    $failure = $e;

    $io->line();
    $io->error($e::class);
    $io->error($e->getMessage());
} finally {
    $io->line();

    foreach ($extraIds as $extraId) {
        try {
            $records->delete($extraId);
        } catch (ExceptionInterface $cleanup) {
            $leaked = true;

            $io->error(sprintf('✗ CLEANUP FAILED for record %d in %s - remove it by hand. %s', $extraId, $zone, $cleanup->getMessage()));
        }
    }

    if ($extraIds !== [] && !$leaked) {
        $io->success(sprintf('cleaned up - %d target probe records deleted', count($extraIds)));
    }

    if ($created?->id === null) {
        $io->info('nothing was created, so there is nothing to clean up');
    } else {
        try {
            $records->delete($created->id);

            $io->success(sprintf('cleaned up - record %d deleted', $created->id));
        } catch (ExceptionInterface $cleanup) {
            $leaked = true;

            $io->error('✗ CLEANUP FAILED - ' . $cleanup::class);
            $io->error($cleanup->getMessage());
            $io->error(sprintf('Remove record %d ("%s") from %s by hand.', $created->id, $name, $zone));
        }
    }
}

// Outside the try, because PHP does not run a `finally` on exit() - an exit inside the block
// would skip the cleanup on exactly the runs that succeeded.
if ($failure !== null || $leaked) {
    exit(1);
}
