<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * DomainRecords with the zone already supplied.
 *
 *     $records = $binarylane->domains()->records('example.com');
 *
 *     $records->all();
 *     $records->upsert(DomainRecord::a('www', '203.0.113.10'));
 *
 * Not an Endpoint subclass: it holds no connection and makes no requests of its own. It is a
 * partial application of the endpoint above, which is worth its file for one reason - a
 * sequence of calls against one zone should not repeat the domain name, and a name repeated
 * by hand is a name that can be wrong on the fourth line, pointing a delete at the wrong zone.
 */
final class BoundDomainRecords
{
    public function __construct(
        private readonly DomainRecords $records,
        public readonly string $domain,
    ) {
    }

    /**
     * The unbound endpoint, for anything not forwarded here.
     */
    public function endpoint(): DomainRecords
    {
        return $this->records;
    }

    /**
     * @return Page<DomainRecord>
     */
    public function list(
        int $page = 1,
        ?int $perPage = null,
        ?DomainRecordType $type = null,
        ?string $name = null,
    ): Page {
        return $this->records->list($this->domain, $page, $perPage, $type, $name);
    }

    /**
     * @return \Generator<int, DomainRecord>
     */
    public function each(?int $perPage = null, ?DomainRecordType $type = null, ?string $name = null): \Generator
    {
        return $this->records->each($this->domain, $perPage, $type, $name);
    }

    /**
     * @return list<DomainRecord>
     */
    public function all(?DomainRecordType $type = null, ?string $name = null): array
    {
        return $this->records->all($this->domain, $type, $name);
    }

    /**
     * @return list<DomainRecord>
     */
    public function matching(DomainRecordType $type, string $name): array
    {
        return $this->records->matching($this->domain, $type, $name);
    }

    public function count(): int
    {
        return $this->records->count($this->domain);
    }

    public function get(int $recordId): DomainRecord
    {
        return $this->records->get($this->domain, $recordId);
    }

    public function find(int $recordId): ?DomainRecord
    {
        return $this->records->find($this->domain, $recordId);
    }

    public function create(DomainRecord $record): DomainRecord
    {
        return $this->records->create($this->domain, $record);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function update(int $recordId, array $changes): DomainRecord
    {
        return $this->records->update($this->domain, $recordId, $changes);
    }

    public function replace(int $recordId, DomainRecord $record): DomainRecord
    {
        return $this->records->replace($this->domain, $recordId, $record);
    }

    public function upsert(DomainRecord $record): DomainRecord
    {
        return $this->records->upsert($this->domain, $record);
    }

    public function delete(int $recordId): void
    {
        $this->records->delete($this->domain, $recordId);
    }
}
