<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

final class DomainRecordsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recordRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 42,
            'type' => 'A',
            'name' => 'www',
            'data' => '203.0.113.10',
            'ttl' => 3600,
            'priority' => null,
            'port' => null,
            'weight' => null,
            'flags' => null,
            'tag' => null,
        ];
    }

    // ------------------------------------------------------------------------------------
    // Building records
    // ------------------------------------------------------------------------------------

    public function testAnARecordSendsOnlyTheFieldsItsTypeCarries(): void
    {
        $this->assertSame(
            ['type' => 'A', 'name' => 'www', 'data' => '203.0.113.10'],
            DomainRecord::a('www', '203.0.113.10')->toArray()
        );
    }

    public function testAnMxRecordCarriesItsPriorityAndNothingElse(): void
    {
        $this->assertSame(
            ['type' => 'MX', 'name' => '@', 'data' => 'mail.example.com', 'priority' => 10],
            DomainRecord::mx('mail.example.com', 10)->toArray()
        );
    }

    /**
     * An MX set and an SRV set are ordered lists, so there is no right default and a wrong one
     * fails silently. Until 0.6.0 mx() defaulted to 10 and srv() to 0 - different from each
     * other, and from other providers' clients.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function orderingParameters(): iterable
    {
        yield 'mx' => ['mx', ['priority']];
        yield 'srv' => ['srv', ['priority', 'weight']];
    }

    /**
     * @param  list<string>  $required
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orderingParameters')]
    public function testTheOrderingOfARecordSetHasNoDefault(string $factory, array $required): void
    {
        $parameters = (new \ReflectionMethod(DomainRecord::class, $factory))->getParameters();

        foreach ($parameters as $parameter) {
            if (in_array($parameter->getName(), $required, true)) {
                $this->assertFalse($parameter->isOptional(), $factory . '() $' . $parameter->getName() . ' has a default');
            }
        }

        $this->assertSame(
            $required,
            array_values(array_intersect($required, array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters)))
        );
    }

    public function testAnSrvRecordCarriesPortPriorityAndWeight(): void
    {
        $this->assertSame(
            [
                'type' => 'SRV',
                'name' => '_sip._tcp',
                'data' => 'sip.example.com',
                'priority' => 10,
                'port' => 5060,
                'weight' => 5,
            ],
            DomainRecord::srv('_sip._tcp', 'sip.example.com', port: 5060, priority: 10, weight: 5)->toArray()
        );
    }

    public function testACaaRecordCarriesItsFlagsAndTag(): void
    {
        $this->assertSame(
            ['type' => 'CAA', 'name' => '@', 'data' => 'letsencrypt.org', 'flags' => 0, 'tag' => 'issue'],
            DomainRecord::caa('issue', 'letsencrypt.org')->toArray()
        );
    }

    /**
     * A record read back carries every extra field as null; echoing it into a create would
     * send fields the type does not take.
     */
    public function testAFetchedRecordDropsTheNullsThatDoNotBelongToItsType(): void
    {
        $record = DomainRecord::fromArray($this->recordRow());

        $this->assertSame(['type' => 'A', 'name' => 'www', 'data' => '203.0.113.10'], $record->toArray());
        $this->assertArrayNotHasKey('priority', $record->toArray());
        $this->assertArrayNotHasKey('ttl', $record->toArray());
    }

    /**
     * BinaryLane writes the apex as `@`, where several other DNS APIs use an empty string.
     */
    public function testAnEmptyNameBecomesTheApexMarker(): void
    {
        $record = DomainRecord::a('', '203.0.113.10');

        $this->assertSame('@', $record->name);
        $this->assertTrue($record->isApex());
    }

    /**
     * The factories converted an empty name; until 0.4.0 the constructor and fromArray() did
     * not, so a record ported from another provider's export sent the empty string.
     */
    public function testAnEmptyNameIsSentAsTheApexHoweverTheRecordWasBuilt(): void
    {
        $constructed = new DomainRecord(DomainRecordType::A, '', '203.0.113.10');
        $imported = DomainRecord::fromArray(['type' => 'TXT', 'name' => '  ', 'data' => 'v=spf1 -all']);

        $this->assertSame('@', $constructed->toArray()['name']);
        $this->assertSame('@', $imported->toArray()['name']);
        $this->assertSame('@', $constructed->toUpdateArray()['name']);
    }

    /**
     * Until 0.4.0 a type this package did not know read as A - and replacing such a record
     * sent it back as one.
     */
    public function testAnUnknownRecordTypeIsNullRatherThanA(): void
    {
        $record = DomainRecord::fromArray(['id' => 5, 'type' => 'TLSA', 'name' => '_443._tcp', 'data' => '3 1 1 abcd']);

        $this->assertNull($record->type);
        $this->assertSame('TLSA', $record->typeName());
        $this->assertFalse($record->isManageable());
        $this->assertSame('TLSA', $record->jsonSerialize()['type']);
    }

    public function testARecordOfUnknownTypeCannotBeSentBack(): void
    {
        $record = DomainRecord::fromArray(['id' => 5, 'type' => 'TLSA', 'name' => '_443._tcp', 'data' => '3 1 1 abcd']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"TLSA"');

        $this->binarylane()->domains()->records('example.com')->replace(5, $record);
    }

    public function testARecordOfUnknownTypeCannotBeUpserted(): void
    {
        $record = DomainRecord::fromArray(['type' => 'TLSA', 'name' => '_443._tcp', 'data' => '3 1 1 abcd']);

        try {
            $this->binarylane()->records()->upsert('example.com', $record);
            $this->fail('expected the unknown type to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('TLSA', $e->getMessage());
        }

        $this->assertSame([], $this->client->requests);
    }

    public function testTheApexAndWildcardMarkersAreRecognised(): void
    {
        $this->assertTrue(DomainRecord::a(DomainRecord::APEX, '203.0.113.10')->isApex());
        $this->assertTrue(DomainRecord::a(DomainRecord::WILDCARD, '203.0.113.10')->isWildcard());
        $this->assertFalse(DomainRecord::a('www', '203.0.113.10')->isApex());
    }

    public function testFqdnResolvesTheApexToTheZoneItself(): void
    {
        $this->assertSame('example.com', DomainRecord::a('@', '203.0.113.10')->fqdn('example.com'));
        $this->assertSame('www.example.com', DomainRecord::a('www', '203.0.113.10')->fqdn('example.com.'));
        $this->assertSame('*.example.com', DomainRecord::a('*', '203.0.113.10')->fqdn('example.com'));
    }

    /**
     * 3600 is the only TTL the API supports, so it is never sent and always reported.
     */
    public function testTheTtlIsFixedAndNotSent(): void
    {
        $record = DomainRecord::a('www', '203.0.113.10');

        $this->assertSame(3600, $record->effectiveTtl());
        $this->assertArrayNotHasKey('ttl', $record->toArray());
        $this->assertSame(3600, DomainRecord::TTL);
    }

    public function testARecordWithNoDataIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainRecord::a('www', '   ');
    }

    public function testACaaTagIsCheckedAgainstThePatternTheSpecificationCarries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('issuewild');

        DomainRecord::caa('Issue', 'letsencrypt.org');
    }

    public function testCaaFlagsAreAnUnsignedByte(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainRecord::caa('issue', 'letsencrypt.org', flags: 256);
    }

    public function testAPriorityAboveSixteenBitsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DomainRecord::mx('mail.example.com', 65536);
    }

    // ------------------------------------------------------------------------------------
    // The endpoint
    // ------------------------------------------------------------------------------------

    public function testCreatePostsToTheZonesRecordCollection(): void
    {
        $this->client->pushJson(200, ['domain_record' => $this->recordRow()]);

        $record = $this->binarylane()->domains()->records('example.com')
            ->create(DomainRecord::a('www', '203.0.113.10'));

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com/records', $this->sentPath());
        $this->assertSame(['type' => 'A', 'name' => 'www', 'data' => '203.0.113.10'], $this->sentBody());
        $this->assertSame(42, $record->id);
    }

    /**
     * Every zone has an SOA and BinaryLane maintains it.
     */
    public function testAnSoaRecordCannotBeCreated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maintained by BinaryLane');

        $this->binarylane()->domains()->records('example.com')->create(
            new DomainRecord(DomainRecordType::SOA, '@', 'ns1.binarylane.com.au')
        );
    }

    /**
     * The update is a PUT that retains what it is not given - so the array is passed through
     * as written, because null and empty string mean opposite things.
     */
    public function testUpdateSendsExactlyWhatItWasGiven(): void
    {
        $this->client->pushJson(200, ['domain_record' => $this->recordRow(['data' => '203.0.113.20'])]);

        $this->binarylane()->domains()->records('example.com')->update(42, ['data' => '203.0.113.20']);

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com/records/42', $this->sentPath());
        $this->assertSame(['data' => '203.0.113.20'], $this->sentBody());
    }

    /**
     * An empty string clears a value; the request must not filter it out as though it were
     * absent.
     */
    public function testAnEmptyStringIsSentBecauseItClearsAValue(): void
    {
        $this->client->pushJson(200, ['domain_record' => $this->recordRow()]);

        $this->binarylane()->domains()->records('example.com')->update(42, ['tag' => '']);

        $this->assertSame(['tag' => ''], $this->sentBody());
    }

    public function testAnUpdateWithNoChangesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->domains()->records('example.com')->update(42, []);
    }

    public function testReplaceSendsEveryFieldTheTypeCarries(): void
    {
        $this->client->pushJson(200, ['domain_record' => $this->recordRow()]);

        $this->binarylane()->domains()->records('example.com')
            ->replace(42, DomainRecord::a('www', '203.0.113.20'));

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame(['type' => 'A', 'name' => 'www', 'data' => '203.0.113.20'], $this->sentBody());
    }

    public function testDeleteAnswersWithNothing(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->domains()->records('example.com')->delete(42);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com/records/42', $this->sentPath());
    }

    public function testTheFiltersAreSentAsQueryParameters(): void
    {
        $this->client->pushJson(200, $this->collection([$this->recordRow()], 'domain_records'));

        $this->binarylane()->domains()->records('example.com')
            ->list(type: DomainRecordType::A, name: 'www');

        $query = urldecode($this->sentQuery());

        $this->assertStringContainsString('type=A', $query);
        $this->assertStringContainsString('name=www', $query);
    }

    public function testAllWalksEveryPage(): void
    {
        $this->client
            ->pushJson(200, $this->collection(
                [$this->recordRow(['id' => 1])],
                'domain_records',
                total: 2,
                next: 'https://api.binarylane.com.au/v2/domains/example.com/records?page=2'
            ))
            ->pushJson(200, $this->collection([$this->recordRow(['id' => 2])], 'domain_records', total: 2));

        $records = $this->binarylane()->domains()->records('example.com')->all();

        $this->assertCount(2, $records);
        $this->assertSame([1, 2], array_map(static fn (DomainRecord $r): ?int => $r->id, $records));
    }

    // ------------------------------------------------------------------------------------
    // upsert - the operation the API does not offer
    // ------------------------------------------------------------------------------------

    public function testUpsertCreatesWhenNothingMatches(): void
    {
        $this->client
            ->pushJson(200, $this->collection([], 'domain_records'))
            ->pushJson(200, ['domain_record' => $this->recordRow()]);

        $this->binarylane()->domains()->records('example.com')
            ->upsert(DomainRecord::a('www', '203.0.113.10'));

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com/records', $this->sentPath());
    }

    public function testUpsertReplacesTheOneThatMatchesAndKeepsItsId(): void
    {
        $this->client
            ->pushJson(200, $this->collection([$this->recordRow()], 'domain_records'))
            ->pushJson(200, ['domain_record' => $this->recordRow(['data' => '203.0.113.20'])]);

        $record = $this->binarylane()->domains()->records('example.com')
            ->upsert(DomainRecord::a('www', '203.0.113.20'));

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com/records/42', $this->sentPath());
        $this->assertSame(42, $record->id);
    }

    /**
     * Two A records for one name is round-robin, not a duplicate - guessing which to change
     * would silently break it.
     */
    public function testUpsertRefusesToGuessWhenSeveralRecordsMatch(): void
    {
        $this->client->pushJson(200, $this->collection([
            $this->recordRow(['id' => 42]),
            $this->recordRow(['id' => 43, 'data' => '203.0.113.11']),
        ], 'domain_records'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('round-robin');

        $this->binarylane()->domains()->records('example.com')
            ->upsert(DomainRecord::a('www', '203.0.113.20'));
    }

    public function testMatchingFiltersByNameAndType(): void
    {
        $this->client->pushJson(200, $this->collection([$this->recordRow()], 'domain_records'));

        $records = $this->binarylane()->domains()->records('example.com')
            ->matching(DomainRecordType::A, 'www');

        $this->assertCount(1, $records);

        $query = urldecode($this->sentQuery());

        $this->assertStringContainsString('type=A', $query);
        $this->assertStringContainsString('name=www', $query);
    }

    public function testARecordIdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->domains()->records('example.com')->get(0);
    }
}
