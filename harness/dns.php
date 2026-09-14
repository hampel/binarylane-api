<?php

/**
 * Exercise: read the DNS zones, and probe whether the record filters are honoured.
 * Read-only - nothing here can change anything.
 *
 * TWO QUESTIONS, AND BOTH ARE INVISIBLE TO THE TEST SUITE.
 *
 * The first is whether `?type=` and `?name=` on the record list actually filter. This
 * package's upsert() decides whether to create or replace on the strength of a filtered
 * list, so a filter that were silently ignored would hand it every record in the zone, it
 * would see "more than one match", and it would refuse - or worse, on a zone with exactly
 * one record, quietly replace the wrong one. A 200 full of records looks like success. The
 * probe asks for a name that cannot exist and compares the count against its own unfiltered
 * baseline: zero means the filter works, and the unfiltered count means it does not.
 *
 * The second is whether `current_nameservers` reports what this package says it does - what
 * the domain ACTUALLY resolves to, rather than what BinaryLane would like. A zone here whose
 * nameservers are somebody else's is serving nothing, and that is a thing worth being able to
 * see on a real account.
 *
 * Needs BINARYLANE_API_TOKEN. Uses BINARYLANE_DNS_ZONE when set, and otherwise takes the
 * first zone on the account.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · dns');

$binarylane = harness_client($io);

try {
    $nameservers = $binarylane->domains()->publicNameservers();

    $io->values([
        'BinaryLane nameservers' => implode(', ', $nameservers) ?: '(none returned)',
        'zones on the account' => $binarylane->domains()->count(),
    ]);

    $io->line();

    $zone = getenv('BINARYLANE_DNS_ZONE') ?: null;
    $domains = [];

    foreach ($binarylane->domains()->each() as $domain) {
        $domains[] = $domain;

        $delegated = $domain->isDelegatedTo($nameservers);

        $io->values([
            $domain->name => sprintf(
                '%s%s',
                $domain->isResolvable() ? implode(', ', $domain->currentNameservers) : 'DOES NOT RESOLVE',
                $delegated ? '' : ' <- not delegated here, so this zone serves nothing'
            ),
        ]);
    }

    if ($domains === []) {
        $io->line();
        $io->warn('no zones on the account, so the record paths and the probe cannot run');

        exit(0);
    }

    $zone ??= $domains[0]->name;

    $io->line();
    $io->info(sprintf('records in %s', $zone));

    $records = $binarylane->domains()->records($zone);
    $all = $records->all();

    $byType = [];

    foreach ($all as $record) {
        $byType[$record->typeName()] = ($byType[$record->typeName()] ?? 0) + 1;
    }

    ksort($byType);

    $io->values(['total records' => count($all)] + array_combine(
        array_map(static fn (string $type): string => '  ' . $type, array_keys($byType)),
        array_values($byType)
    ));

    $ttls = array_values(array_unique(array_map(
        static fn (DomainRecord $record): int => $record->effectiveTtl(),
        $all
    )));

    $io->line();
    $io->values([
        'distinct TTLs' => implode(', ', $ttls) ?: '(none)',
    ]);

    if ($ttls !== [] && $ttls !== [DomainRecord::TTL]) {
        $io->warn(sprintf(
            'the specification says 3600 is the only supported TTL, and this zone has %s.',
            implode(', ', $ttls)
        ));
        $io->warn('Either that claim is out of date or these records predate it. Worth chasing.');
    } elseif ($ttls !== []) {
        $io->success('all 3600, as the specification says they must be');
    }

    // ------------------------------------------------------------------------------------
    // The probe.
    // ------------------------------------------------------------------------------------

    $io->line();
    $io->info('probe: are ?type= and ?name= actually honoured?');

    // A name that cannot match anything. If the filter is honoured we expect zero; if it is
    // silently ignored we get the unfiltered count, which is the dangerous outcome because
    // nothing errors and every mocked test still passes.
    $needle = 'zz-no-such-record-' . bin2hex(random_bytes(4));

    // The baseline is fetched here rather than reusing count($all) above: when the two
    // agree, that agreement says both requests looked at the same zone, which is the
    // assumption the comparison rests on.
    $baseline = $records->all();
    $missing = $records->all(name: $needle);

    $io->values([
        'unfiltered records' => count($baseline),
        'records named ' . $needle => count($missing),
    ]);

    if (count($missing) === 0) {
        $io->success('the name filter is honoured');
    } elseif (count($missing) === count($baseline)) {
        $io->error('IGNORED - the filtered request returned the whole zone.');
        $io->error('upsert() cannot be trusted against this API until that is understood.');
    } else {
        $io->warn(sprintf('neither zero nor the whole zone (%d) - look at what came back', count($baseline)));
    }

    $io->line();

    $aRecords = $records->all(type: DomainRecordType::A);

    $io->values([
        'unfiltered records ' => count($baseline),
        'records of type A' => count($aRecords),
    ]);

    if (count($aRecords) === count($baseline) && count($byType) > 1) {
        $io->error('IGNORED - the type filter returned the whole zone, which has more than one type in it.');
    } elseif (count($byType) > 1) {
        $io->success('the type filter is honoured');
    } else {
        $io->warn('this zone has only one record type, so the type filter cannot be told apart from no filter');
    }

    $io->line();
    $io->info('apex and wildcard, as this API writes them');

    foreach ($all as $record) {
        if ($record->isApex() || $record->isWildcard()) {
            $io->values([
                sprintf('%s %s', $record->typeName(), $record->name) => $record->fqdn($zone) . ' -> ' . ($record->data ?? '(none)'),
            ]);
        }
    }
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}
