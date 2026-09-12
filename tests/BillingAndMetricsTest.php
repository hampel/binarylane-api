<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Invoice;
use Hampel\BinaryLane\Api\Enum\DataInterval;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Balance, invoices, data usage, performance samples and the account-wide IPv6 reverse
 * nameservers - the read-only reporting surface, plus the one mutation in it.
 */
final class BillingAndMetricsTest extends TestCase
{
    // ------------------------------------------------------------------------------------
    // Balance and invoices
    // ------------------------------------------------------------------------------------

    public function testTheBalanceSeparatesWhatIsOwedFromWhatIsAvailable(): void
    {
        $this->client->pushJson(200, ['balance' => [
            'unbilled_total' => 120.50,
            'available_credit' => 100.00,
            'generated_at' => '2026-09-12T00:00:00Z',
            'charges' => [
                ['created' => '2026-09-01T00:00:00Z', 'description' => 'vps01 monthly', 'total' => 100.0, 'ongoing' => true],
                ['created' => '2026-09-05T00:00:00Z', 'description' => 'excess transfer', 'total' => 20.50, 'ongoing' => false],
            ],
        ]]);

        $balance = $this->binarylane()->billing()->balance();

        $this->assertSame('/v2/customers/my/balance', $this->sentPath());
        $this->assertEqualsWithDelta(20.50, $balance->shortfall(), 0.001);
        $this->assertFalse($balance->isCovered());
        $this->assertCount(1, $balance->ongoingCharges());
        $this->assertEqualsWithDelta(100.0, $balance->ongoingTotal(), 0.001);
    }

    public function testACoveredBalanceHasNoShortfall(): void
    {
        $this->client->pushJson(200, ['balance' => [
            'unbilled_total' => 50.0,
            'available_credit' => 100.0,
            'charges' => [],
        ]]);

        $balance = $this->binarylane()->billing()->balance();

        $this->assertSame(0.0, $balance->shortfall(), 'a surplus is not a negative shortfall');
        $this->assertTrue($balance->isCovered());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoiceRow(array $overrides = []): array
    {
        return $overrides + [
            'invoice_id' => 501,
            'invoice_number' => 'INV-2026-0501',
            'amount' => 110.0,
            'tax' => 10.0,
            'tax_code' => ['name' => 'GST', 'type' => 'scalar', 'fixed_percent' => 10.0],
            'created' => '2026-09-01T00:00:00Z',
            'date_due' => '2026-09-15T00:00:00Z',
            'date_overdue' => '2026-09-22T00:00:00Z',
            'paid' => false,
            'refunded' => false,
            'invoice_items' => [
                ['name' => 'vps01 monthly', 'amount' => 120.0, 'amount_includes_tax' => true],
                ['name' => 'loyalty discount', 'amount' => -10.0, 'amount_includes_tax' => true],
            ],
            'invoice_download_url' => 'https://billing.example.test/501.pdf?sig=secret',
        ];
    }

    public function testAnInvoiceIsFetchedByItsIntegerId(): void
    {
        $this->client->pushJson(200, ['invoice' => $this->invoiceRow()]);

        $invoice = $this->binarylane()->billing()->invoice(501);

        $this->assertSame('/v2/customers/my/invoices/501', $this->sentPath());
        $this->assertSame('INV-2026-0501', $invoice->invoiceNumber);
        $this->assertEqualsWithDelta(100.0, $invoice->amountExcludingTax(), 0.001);
        $this->assertCount(1, $invoice->credits());
    }

    /**
     * A refunded invoice is not outstanding, however unpaid it looks.
     */
    public function testARefundedInvoiceIsNotOutstanding(): void
    {
        $invoice = Invoice::fromArray($this->invoiceRow(['paid' => false, 'refunded' => true]));

        $this->assertFalse($invoice->isOutstanding());
        $this->assertFalse($invoice->isOverdue(new \DateTimeImmutable('2027-01-01T00:00:00Z')));
    }

    public function testAnUnpaidInvoiceBecomesOverdueOnItsOverdueDate(): void
    {
        $invoice = Invoice::fromArray($this->invoiceRow());

        $this->assertTrue($invoice->isOutstanding());
        $this->assertFalse($invoice->isOverdue(new \DateTimeImmutable('2026-09-20T00:00:00Z')));
        $this->assertTrue($invoice->isOverdue(new \DateTimeImmutable('2026-09-23T00:00:00Z')));
    }

    public function testAFailedPaymentIsTheConditionThatBlocksThings(): void
    {
        $invoice = Invoice::fromArray($this->invoiceRow(['payment_failure_count' => 2]));

        $this->assertTrue($invoice->hasFailedPayment());
        $this->assertFalse(Invoice::fromArray($this->invoiceRow())->hasFailedPayment());
    }

    public function testTheInvoiceUrlsAreWithheldFromDebugOutput(): void
    {
        $invoice = Invoice::fromArray($this->invoiceRow());

        $this->assertStringNotContainsString('sig=secret', print_r($invoice, true));
    }

    public function testTheBlockingInvoicesAreNotPaginated(): void
    {
        $this->client->pushJson(200, ['invoices' => [$this->invoiceRow(['payment_failure_count' => 1])]]);

        $blocking = $this->binarylane()->billing()->unpaidFailedInvoices();

        $this->assertSame('/v2/customers/my/unpaid-payment-failed-invoices', $this->sentPath());
        $this->assertCount(1, $blocking);
        $this->assertTrue($blocking[0]->hasFailedPayment());
    }

    public function testIsBlockedIsFalseOnAHealthyAccount(): void
    {
        $this->client->pushJson(200, ['invoices' => []]);

        $this->assertFalse($this->binarylane()->billing()->isBlocked());
    }

    public function testAnInvoiceIdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->billing()->invoice(0);
    }

    // ------------------------------------------------------------------------------------
    // Data usage
    // ------------------------------------------------------------------------------------

    public function testDataUsageIsReadPerServer(): void
    {
        $this->client->pushJson(200, ['data_usage' => [
            'server_id' => 1234,
            'transfer_gigabytes' => 1000,
            'current_transfer_usage_gigabytes' => 250.5,
            'expires' => '2026-10-01T00:00:00Z',
        ]]);

        $usage = $this->binarylane()->dataUsages()->forServer(1234);

        $this->assertSame('/v2/data_usages/1234/current', $this->sentPath());
        $this->assertEqualsWithDelta(25.05, $usage->usagePercent() ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(749.5, $usage->remainingGigabytes(), 0.001);
        $this->assertFalse($usage->isOverAllowance());
    }

    /**
     * The allowance is pooled, so the comparison that means something is the total.
     */
    public function testTheAccountTotalsAreWhatThePooledAllowanceIsComparedOn(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['server_id' => 1, 'transfer_gigabytes' => 1000, 'current_transfer_usage_gigabytes' => 1200.0],
            ['server_id' => 2, 'transfer_gigabytes' => 1000, 'current_transfer_usage_gigabytes' => 300.0],
        ], 'data_usages'));

        $usages = $this->binarylane()->dataUsages()->all();

        $this->assertSame([1, 2], array_keys($usages));
        $this->assertTrue($usages[1]->isOverAllowance(), 'over its own number');

        $this->client->pushJson(200, $this->collection([
            ['server_id' => 1, 'transfer_gigabytes' => 1000, 'current_transfer_usage_gigabytes' => 1200.0],
            ['server_id' => 2, 'transfer_gigabytes' => 1000, 'current_transfer_usage_gigabytes' => 300.0],
        ], 'data_usages'));

        $this->assertSame(2000.0, $this->binarylane()->dataUsages()->total());
    }

    // ------------------------------------------------------------------------------------
    // Sample sets
    // ------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function sampleSetRow(): array
    {
        return [
            'server_id' => 1234,
            'period' => [
                'start' => '2026-09-12T00:00:00Z',
                'end' => '2026-09-12T00:05:00Z',
                'data_interval' => 'five-minute',
            ],
            'average' => [
                'cpu_usage_percent' => 42.5,
                'cpu_usage_detailed' => [80.0, 5.0],
                'memory_usage_bytes' => 2147483648.0,
                'network_incoming_kbps' => 1024.0,
                'network_outgoing_kbps' => 512.0,
                'storage_usage_megabytes' => 40960.0,
                'storage_read_kbps' => 100.0,
                'storage_write_kbps' => 50.0,
                'storage_read_requests_per_second' => 12.0,
                'storage_write_requests_per_second' => 8.0,
            ],
            'maximum_memory_megabytes' => 3072.0,
            'maximum_storage_gigabytes' => 45.0,
        ];
    }

    public function testTheLatestSampleSetIsOneRequest(): void
    {
        $this->client->pushJson(200, ['sample_set' => $this->sampleSetRow()]);

        $set = $this->binarylane()->sampleSets()->latest(1234, DataInterval::FiveMinute);

        $this->assertSame('/v2/samplesets/1234/latest', $this->sentPath());
        $this->assertStringContainsString('data_interval=five-minute', urldecode($this->sentQuery()));
        $this->assertSame(300, $set->period?->seconds());
    }

    /**
     * The units are mixed and the field names carry them; the accessors do the conversions.
     */
    public function testTheSampleConvertsItsMixedUnits(): void
    {
        $this->client->pushJson(200, ['sample_set' => $this->sampleSetRow()]);

        $sample = $this->binarylane()->sampleSets()->latest(1234)->average;

        $this->assertNotNull($sample);
        $this->assertSame(2048.0, $sample->memoryMegabytes());
        $this->assertSame(40.0, $sample->storageGigabytes());
        $this->assertSame(20.0, $sample->storageRequestsPerSecond());
        $this->assertSame(2, $sample->vcpus());
        $this->assertSame(80.0, $sample->busiestCpuPercent(), 'the average hides a pinned core');
        $this->assertSame(42.5, $sample->cpuUsagePercent);
        $this->assertEqualsWithDelta(8.389, $sample->networkIncomingMbps(), 0.001);
    }

    public function testPeakUsageIsComparedAgainstTheServersOwnSize(): void
    {
        $this->client->pushJson(200, ['sample_set' => $this->sampleSetRow()]);

        $set = $this->binarylane()->sampleSets()->latest(1234);

        $this->assertEqualsWithDelta(75.0, $set->peakMemoryPercentOf(4096) ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(56.25, $set->peakStoragePercentOf(80) ?? 0.0, 0.001);
        $this->assertNull($set->peakMemoryPercentOf(0));
    }

    public function testAnExplicitWindowIsSentAsUtcTimestamps(): void
    {
        $this->client->pushJson(200, $this->collection([$this->sampleSetRow()], 'sample_sets'));

        $this->binarylane()->sampleSets()->between(
            1234,
            new \DateTimeImmutable('2026-09-01 10:00:00', new \DateTimeZone('Australia/Sydney')),
            new \DateTimeImmutable('2026-09-02T00:00:00Z'),
            DataInterval::Day,
        );

        $query = urldecode($this->sentQuery());

        $this->assertStringContainsString('start=2026-09-01T00:00:00Z', $query, 'converted to UTC');
        $this->assertStringContainsString('end=2026-09-02T00:00:00Z', $query);
        $this->assertStringContainsString('data_interval=day', $query);
    }

    public function testAWindowThatRunsBackwardsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->sampleSets()->between(
            1234,
            new \DateTimeImmutable('2026-09-02T00:00:00Z'),
            new \DateTimeImmutable('2026-09-01T00:00:00Z'),
        );
    }

    // ------------------------------------------------------------------------------------
    // Reverse names
    // ------------------------------------------------------------------------------------

    /**
     * The only collection in the API that is a list of strings rather than of objects.
     */
    public function testTheReverseNameserversAreAListOfStrings(): void
    {
        $this->client->pushJson(200, [
            'reverse_nameservers' => ['ns1.example.test', 'ns2.example.test'],
            'meta' => ['total' => 2],
        ]);

        $names = $this->binarylane()->reverseNames()->all();

        $this->assertSame(['ns1.example.test', 'ns2.example.test'], $names);
        $this->assertSame('/v2/reverse_names/ipv6', $this->sentPath());
    }

    /**
     * The API replaces the set, so adding one means reading the others first.
     */
    public function testAddingANameserverSendsTheWholeSetBack(): void
    {
        $this->client
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test'], 'meta' => ['total' => 1]])
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test', 'ns2.example.test'], 'meta' => ['total' => 2]]);

        $names = $this->binarylane()->reverseNames()->add(['ns2.example.test']);

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame(
            ['reverse_nameservers' => ['ns1.example.test', 'ns2.example.test']],
            $this->sentBody()
        );
        $this->assertSame(['ns1.example.test', 'ns2.example.test'], $names);
    }

    public function testAddingOneThatIsAlreadyThereChangesNothing(): void
    {
        $this->client
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test'], 'meta' => ['total' => 1]])
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test'], 'meta' => ['total' => 1]]);

        $this->binarylane()->reverseNames()->add(['ns1.example.test']);

        $this->assertSame(['reverse_nameservers' => ['ns1.example.test']], $this->sentBody());
    }

    public function testClearingRemovesThemAll(): void
    {
        $this->client->pushJson(200, ['reverse_nameservers' => [], 'meta' => ['total' => 0]]);

        $this->assertSame([], $this->binarylane()->reverseNames()->clear());
        $this->assertSame(['reverse_nameservers' => []], $this->sentBody());
    }

    public function testRemovingKeepsTheRest(): void
    {
        $this->client
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test', 'ns2.example.test'], 'meta' => ['total' => 2]])
            ->pushJson(200, ['reverse_nameservers' => ['ns1.example.test'], 'meta' => ['total' => 1]]);

        $this->binarylane()->reverseNames()->remove(['ns2.example.test']);

        $this->assertSame(['reverse_nameservers' => ['ns1.example.test']], $this->sentBody());
    }
}
