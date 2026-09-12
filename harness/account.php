<?php

/**
 * Exercise: read the account behind the token. Read-only - nothing here can change anything.
 *
 * The cheapest proof that a token works, and the three facts that make later requests fail
 * for reasons that have nothing to do with the request: the account's status, whether its
 * email is verified, and whether an unpaid invoice is currently blocking new services.
 *
 * Questions this settles that the suite cannot:
 *
 *   - does GET /v2/account answer the envelope this package expects?
 *   - are the timestamps in the shape Cast::datetime assumes? The specification documents
 *     them as "ISO8601" with one worked example anywhere in the document, so the wire form
 *     is inference until something reads a real one.
 *   - is `unpaid-payment-failed-invoices` empty, and is it a bare list rather than a paged
 *     collection? It is the only invoice endpoint the specification declares without `meta`.
 *
 * Needs BINARYLANE_API_TOKEN.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\BinaryLane\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('binarylane · account');

$binarylane = harness_client($io);

try {
    $account = $binarylane->verify();

    $io->success('the token works');
    $io->line();

    $io->values([
        'email' => $account->email,
        'email verified' => $account->emailVerified ? 'yes' : 'NO - some operations are restricted',
        'status' => $account->status?->value ?? '(unrecognised)',
        'two factor' => $account->twoFactorAuthenticationEnabled ? 'yes' : 'no',
        'additional IPv4 limit' => $account->additionalIpv4Limit,
        'payment methods' => implode(', ', array_map(
            static fn ($method) => $method->value,
            $account->configuredPaymentMethods
        )) ?: 'NONE CONFIGURED',
    ]);

    if (!$account->isActive()) {
        $io->line();
        $io->warn('the account is not active - expect 400s that are about the account, not the request');
    }

    if ($account->unknownPaymentMethods !== []) {
        $io->line();
        $io->warn('payment methods this package has no case for: ' . implode(', ', $account->unknownPaymentMethods));
    }

    $tax = $account->taxCode;

    if ($tax !== null) {
        $io->line();
        $io->values([
            'tax code' => $tax->name,
            'tax applies' => $tax->applies() ? 'yes' : 'no',
            'tax multiplier' => $tax->multiplier(),
        ]);
    }

    $io->line();
    $io->info('balance');

    $balance = $binarylane->billing()->balance();

    $io->values([
        'unbilled total' => sprintf('AU$%.2f', $balance->unbilledTotal),
        'available credit' => sprintf('AU$%.2f', $balance->availableCredit),
        'shortfall' => sprintf('AU$%.2f', $balance->shortfall()),
        'ongoing charges' => sprintf('AU$%.2f across %d', $balance->ongoingTotal(), count($balance->ongoingCharges())),
        'generated at' => $balance->generatedAt?->format(DATE_ATOM) ?? '(not sent)',
    ]);

    if ($balance->generatedAt !== null) {
        $io->line();
        $io->info(sprintf(
            'the timestamp parsed to %s UTC - if that is not the instant BinaryLane meant, '
                . 'Cast::datetime has the wrong assumption',
            $balance->generatedAt->format('Y-m-d H:i:s')
        ));
    }

    $io->line();
    $io->info('anything blocking new or renewed services');

    $blocking = $binarylane->billing()->unpaidFailedInvoices();

    if ($blocking === []) {
        $io->success('nothing - no unpaid invoice with a failed payment');
    } else {
        foreach ($blocking as $invoice) {
            $io->warn(sprintf(
                'invoice %s (#%d): AU$%.2f, %d failed payment attempt(s)',
                $invoice->invoiceNumber,
                $invoice->invoiceId,
                $invoice->amount,
                $invoice->paymentFailureCount ?? 0
            ));
        }

        $io->line();
        $io->warn('an action started while this is true can wait indefinitely - see Action::$blockingInvoiceId');
    }
} catch (ExceptionInterface $e) {
    $io->error($e::class);
    $io->error($e->getMessage());

    exit(1);
}
