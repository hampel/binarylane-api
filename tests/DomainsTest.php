<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Domain;
use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;

final class DomainsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function domainRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 77,
            'name' => 'example.com',
            'current_nameservers' => ['ns1.binarylane.com.au', 'ns2.binarylane.com.au'],
            'ttl' => 3600,
            'zone_file' => '$ORIGIN example.com.' . "\n",
        ];
    }

    public function testItReadsAZoneByName(): void
    {
        $this->client->pushJson(200, ['domain' => $this->domainRow()]);

        $domain = $this->binarylane()->domains()->get('example.com');

        $this->assertSame('/v2/domains/example.com', $this->sentPath());
        $this->assertSame('example.com', $domain->name);
        $this->assertSame(77, $domain->id);
    }

    /**
     * `example.com.` is how a zone is written in a zone file and reported by several DNS
     * tools; it is not what this API's paths take.
     */
    public function testItStripsATrailingDotAndLowercasesTheName(): void
    {
        $this->client->pushJson(200, ['domain' => $this->domainRow()]);

        $this->binarylane()->domains()->get('  Example.COM.  ');

        $this->assertSame('/v2/domains/example.com', $this->sentPath());
    }

    public function testAnEmptyDomainNameIsRefusedBeforeARequestIsMade(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->binarylane()->domains()->get('  .  ');
        } finally {
            $this->assertSame([], $this->client->requests);
        }
    }

    public function testFindAnswersNullForAZoneTheAccountDoesNotHold(): void
    {
        $this->client->pushJson(404, $this->problem('Not Found'));

        $this->assertNull($this->binarylane()->domains()->find('nothere.example'));
    }

    public function testGetStillRaisesForAZoneTheAccountDoesNotHold(): void
    {
        $this->client->pushJson(404, $this->problem('Not Found'));

        $this->expectException(NotFoundException::class);

        $this->binarylane()->domains()->get('nothere.example');
    }

    /**
     * `currentNameservers` is what the domain really resolves to, not what BinaryLane would
     * like - so a zone can exist here and serve nothing.
     */
    public function testAZoneThatIsNotDelegatedSaysSo(): void
    {
        $this->client->pushJson(200, ['domain' => $this->domainRow([
            'current_nameservers' => ['ns1.someone-else.example', 'ns2.someone-else.example'],
        ])]);

        $domain = $this->binarylane()->domains()->get('example.com');

        $this->assertTrue($domain->isResolvable());
        $this->assertFalse($domain->isDelegatedTo(['ns1.binarylane.com.au', 'ns2.binarylane.com.au']));
    }

    public function testDelegationIsComparedWithoutCaseOrTrailingDots(): void
    {
        $domain = Domain::fromArray($this->domainRow([
            'current_nameservers' => ['NS1.BinaryLane.com.au.', 'ns2.binarylane.com.au.'],
        ]));

        $this->assertTrue($domain->isDelegatedTo(['ns1.binarylane.com.au', 'ns2.binarylane.com.au']));
    }

    /**
     * A domain delegated to two nameservers, one of which is somebody else's, is not
     * delegated.
     */
    public function testHalfADelegationIsNotADelegation(): void
    {
        $domain = Domain::fromArray($this->domainRow([
            'current_nameservers' => ['ns1.binarylane.com.au', 'ns2.someone-else.example'],
        ]));

        $this->assertFalse($domain->isDelegatedTo(['ns1.binarylane.com.au', 'ns2.binarylane.com.au']));
    }

    public function testADomainWithNoNameserversIsNotDelegatedAndNotResolvable(): void
    {
        $domain = Domain::fromArray($this->domainRow(['current_nameservers' => []]));

        $this->assertFalse($domain->isResolvable());
        $this->assertFalse($domain->isDelegatedTo(['ns1.binarylane.com.au']));
    }

    public function testCreateCanSeedAnApexARecord(): void
    {
        $this->client->pushJson(200, ['domain' => $this->domainRow()]);

        $this->binarylane()->domains()->create('Example.com', '203.0.113.10');

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame(['name' => 'example.com', 'ip_address' => '203.0.113.10'], $this->sentBody());
    }

    public function testDeleteIsADeleteThatAnswersWithNothing(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->domains()->delete('example.com');

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame('/v2/domains/example.com', $this->sentPath());
    }

    public function testThePublicNameserversAreNotPaginated(): void
    {
        $this->client->pushJson(200, ['local_nameservers' => ['ns1.binarylane.com.au', 'ns2.binarylane.com.au']]);

        $nameservers = $this->binarylane()->domains()->publicNameservers();

        $this->assertSame(['ns1.binarylane.com.au', 'ns2.binarylane.com.au'], $nameservers);
        $this->assertSame('/v2/domains/nameservers', $this->sentPath());
    }

    public function testRefreshingTheNameserverCacheNormalisesTheNames(): void
    {
        $this->client->pushJson(200, []);

        $this->binarylane()->domains()->refreshNameserverCache(['Example.com.', 'other.example']);

        $this->assertSame('/v2/domains/refresh_nameserver_cache', $this->sentPath());
        $this->assertSame(['domain_names' => ['example.com', 'other.example']], $this->sentBody());
    }

    public function testRefreshingNothingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->domains()->refreshNameserverCache([]);
    }

    public function testListPaginates(): void
    {
        $this->client->pushJson(200, $this->collection([$this->domainRow()], 'domains', total: 9));

        $page = $this->binarylane()->domains()->list();

        $this->assertSame(9, $page->total);
        $this->assertSame('example.com', $page->first()?->name);
    }

    public function testCountAsksForNoItems(): void
    {
        $this->client->pushJson(200, ['domains' => [], 'meta' => ['total' => 9]]);

        $this->assertSame(9, $this->binarylane()->domains()->count());
        $this->assertSame('per_page=0', $this->sentQuery());
    }

    public function testRecordsBindsTheZoneName(): void
    {
        $this->client->pushJson(200, $this->collection([], 'domain_records'));

        $records = $this->binarylane()->domains()->records('Example.com.');

        $this->assertSame('example.com', $records->domain);

        $records->list();

        $this->assertSame('/v2/domains/example.com/records', $this->sentPath());
    }

    public function testItKeepsUnrecognisedFields(): void
    {
        $this->client->pushJson(200, ['domain' => $this->domainRow(['something_new' => 'value'])]);

        $this->assertSame('value', $this->binarylane()->domains()->get('example.com')->raw['something_new']);
    }

    public function testDomainRecordsCanBeReachedUnbound(): void
    {
        $this->client->pushJson(200, ['domain_record' => [
            'id' => 42, 'type' => 'A', 'name' => 'www', 'data' => '203.0.113.10', 'ttl' => 3600,
        ]]);

        $record = $this->binarylane()->records()->get('example.com', 42);

        $this->assertSame(DomainRecordType::A, $record->type);
        $this->assertSame('/v2/domains/example.com/records/42', $this->sentPath());
        $this->assertInstanceOf(DomainRecord::class, $record);
    }
}
