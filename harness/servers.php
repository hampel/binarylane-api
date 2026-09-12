<?php

/**
 * Exercise: read the account's servers and what is happening to them. Read-only - nothing
 * here can change anything.
 *
 * The fields worth looking at with real data in them, because several of them are the ones
 * this package tells callers to read INSTEAD of the obvious neighbour:
 *
 *   - `selected_size_options` against the size's own numbers. They disagree on any server
 *     that has ever been given extra memory or disk, and the package says to read the first.
 *     If they never disagree on this account, that advice is untested here.
 *   - the address lists, which are split by family and not by reachability - so a server in
 *     a VPC has private addresses sitting in `v4` alongside public ones.
 *   - `netmask`, which the specification declares as either an integer or a string. An IPv6
 *     network should report a prefix length and an IPv4 one a dotted mask; this prints both
 *     so the claim can be checked rather than assumed.
 *   - `is_under_maintenance`, and the fact that `status` is NOT a power state: a server that
 *     is powered off reports `active` like everything else, which is why the package offers no
 *     isRunning() on a Server. Between them these explain most otherwise inexplicable 400s.
 *
 * It also reads the transfer usage, which is POOLED across the account - so a server over its
 * own allowance may be perfectly fine, and only the total says anything.
 *
 * Needs BINARYLANE_API_TOKEN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Entity\Network;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · servers');

$binarylane = harness_client($io);

try {
    $total = $binarylane->servers()->count();

    $io->info(sprintf('%d server(s) on the account', $total));

    if ($total === 0) {
        $io->line();
        $io->warn('nothing to look at. The read paths are exercised, the field-level questions are not.');

        exit(0);
    }

    foreach ($binarylane->servers()->each() as $server) {
        $io->line();
        $io->info($server->describe());

        $io->values([
            'permalink' => $server->permalink ?? '(none)',
            'region' => $server->regionSlug() ?? '(none)',
            'status' => ($server->status?->value ?? '(unrecognised)') . ' - a lifecycle state, not a power state',
            'under maintenance' => $server->isUnderMaintenance ? 'YES - most actions will be refused' : 'no',
            'actionable' => $server->isActionable() ? 'yes' : 'NO',
            'cancelled' => $server->isCancelled() ? 'YES' : 'no',
            'in a VPC' => $server->isInVpc() ? 'vpc ' . $server->vpcId : 'no',
            'password reset' => $server->supportsPasswordReset() ? 'supported' : 'NOT supported',
        ]);

        $selected = $server->selectedSizeOptions;
        $size = $server->size;

        if ($selected !== null && $size !== null) {
            $io->line();
            $io->values([
                'server memory / size memory' => sprintf('%d MB / %d MB', $selected->memory, $size->memory),
                'server disk / size disk' => sprintf('%d GB / %d GB', $selected->disk, $size->disk),
                'server transfer / size' => sprintf('%.1f TB / %.1f TB', $selected->transfer, $size->transfer),
                'backup slots' => $selected->backupSlots(),
                'IPv4 addresses' => $selected->ipv4Addresses,
            ]);

            if ($selected->memory !== $size->memory || $selected->disk !== $size->disk) {
                $io->info('the two disagree - which is the case that makes reading the size wrong');
            }
        }

        $networks = $server->networks;

        if ($networks !== null) {
            $io->line();
            $io->values([
                'public address' => $server->publicAddress() ?? 'NONE',
                'private addresses' => implode(', ', $server->privateAddresses()) ?: '(none)',
                'mac address' => $networks->macAddress,
                'port blocking' => $networks->portBlocking ? 'on (TCP 22, 25, 3389 outbound)' : 'off',
                'recent DDoS' => $networks->recentDdos ? 'YES - BinaryLane will have emailed' : 'no',
            ]);

            foreach ($networks->all() as $network) {
                $io->values([
                    '  ' . $network->ipAddress => sprintf(
                        '%s, netmask %s (%s), prefix /%s',
                        $network->type?->value ?? '?',
                        var_export($network->netmask, true),
                        get_debug_type($network->netmask),
                        $network->prefixLength() ?? '?'
                    ),
                ]);
            }

            $natted = array_values(array_filter($networks->all(), static fn (Network $n): bool => $n->isNatted()));

            if ($natted !== []) {
                $io->info(sprintf('%d address(es) are NATted onto a private target', count($natted)));
            }
        }

        $io->line();
        $io->values([
            'backups' => count($server->backupIds),
            'disks' => sprintf(
                '%d (%d additional)',
                count($server->disks),
                count($server->additionalDisks())
            ),
            'next backup window' => $server->nextBackupWindow?->start?->format(DATE_ATOM) ?? '(none scheduled)',
            'attached backup' => $server->hasAttachedBackup() ? 'YES - something is mid-recovery' : 'no',
        ]);
    }

    $io->line();
    $io->info('threshold alerts currently over their limit, across the whole fleet');

    $exceeded = $binarylane->servers()->serversWithExceededAlerts();

    if ($exceeded === []) {
        $io->success('none');
    } else {
        $io->warn('server ids: ' . implode(', ', $exceeded));
    }

    $io->line();
    $io->info('data transfer this period - the allowance is POOLED, so read the total');

    $usages = $binarylane->dataUsages()->all();

    foreach ($usages as $serverId => $usage) {
        $io->values([
            (string) $serverId => sprintf(
                '%.2f GB used of a %d GB contribution%s',
                $usage->currentTransferUsageGigabytes,
                $usage->transferGigabytes,
                $usage->isOverAllowance() ? ' - over its own number' : ''
            ),
        ]);
    }

    if ($usages !== []) {
        $allowance = 0.0;
        $used = 0.0;

        foreach ($usages as $usage) {
            $allowance += $usage->transferGigabytes;
            $used += $usage->currentTransferUsageGigabytes;
        }

        $io->line();
        $io->values([
            'pooled allowance' => sprintf('%.0f GB', $allowance),
            'pooled usage' => sprintf('%.2f GB', $used),
            'pooled remaining' => sprintf('%.2f GB', $allowance - $used),
        ]);
    }

    $io->line();
    $io->info('the most recent action on the account');

    $recent = $binarylane->actions()->list(perPage: 1)->first();

    if ($recent === null) {
        $io->line('(none)');
    } else {
        $io->values([
            'action' => $recent->describe(),
            'started' => $recent->startedAt?->format(DATE_ATOM) ?? '(not sent)',
            'completed' => $recent->completedAt?->format(DATE_ATOM) ?? '(still running)',
            'result data' => $recent->resultData ?? '(none)',
        ]);
    }
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}
