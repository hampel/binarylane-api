<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\DomainRecord;
use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * The records inside a DNS zone.
 *
 * `/v2/domains/{domain}/records`
 *
 * Every method takes the zone name first. For several calls against one zone,
 * `$binarylane->domains()->records('example.com')` binds it once - see BoundDomainRecords.
 *
 * THE UPDATE IS A PUT THAT BEHAVES LIKE A PATCH, and the rule has a sharp edge. The
 * specification: "Any values not provided will be retained. Provide empty strings to clear
 * existing string values, nulls to retain the existing values."
 *
 * So on an update null and empty string are OPPOSITES - null keeps, `""` clears. That is
 * backwards from most APIs and from this API's own create. update() takes an array so the
 * distinction is expressible; replace() takes a whole record for when the record in hand is
 * the one you mean to end up with.
 *
 * THE TTL IS NOT YOURS TO SET. 3600 is the only value supported - see Entity\DomainRecord.
 */
final class DomainRecords extends Endpoint
{
    public const COLLECTION = 'domain_records';

    /**
     * One record by id. Raises NotFoundException when there is no such record in the zone.
     */
    public function get(string $domain, int $recordId): DomainRecord
    {
        return $this->apiObject($this->path($domain, $recordId), 'domain_record', DomainRecord::fromArray(...));
    }

    /**
     * One record by id, or null.
     */
    public function find(string $domain, int $recordId): ?DomainRecord
    {
        return $this->apiFind($this->path($domain, $recordId), 'domain_record', DomainRecord::fromArray(...));
    }

    /**
     * One page of a zone's records.
     *
     * THE FILTERS ARE APPLIED BY THE API, so they cost nothing and narrow what comes back.
     * `$name` matches the record name as stored - `www`, or `@` for the apex - not the fully
     * qualified name.
     *
     * @return Page<DomainRecord>
     */
    public function list(
        string $domain,
        int $page = 1,
        ?int $perPage = null,
        ?DomainRecordType $type = null,
        ?string $name = null,
    ): Page {
        return $this->apiPaginate(
            $this->path($domain),
            self::COLLECTION,
            DomainRecord::fromArray(...),
            $page,
            $perPage,
            $this->filters($type, $name)
        );
    }

    /**
     * Every record in a zone, a page at a time.
     *
     * @return \Generator<int, DomainRecord>
     */
    public function each(
        string $domain,
        ?int $perPage = null,
        ?DomainRecordType $type = null,
        ?string $name = null,
    ): \Generator {
        return $this->apiEach(
            $this->path($domain),
            self::COLLECTION,
            DomainRecord::fromArray(...),
            $perPage,
            $this->filters($type, $name)
        );
    }

    /**
     * Every record in a zone, as a list.
     *
     * A whole zone is small enough to hold, and several of the things worth doing with DNS -
     * comparing against a desired state, checking nothing else claims a name - need all of it
     * at once rather than a page at a time.
     *
     * @return list<DomainRecord>
     */
    public function all(string $domain, ?DomainRecordType $type = null, ?string $name = null): array
    {
        return iterator_to_array($this->each($domain, null, $type, $name), false);
    }

    /**
     * How many records a zone has, in one request that fetches none of them.
     */
    public function count(string $domain): int
    {
        return $this->apiCount($this->path($domain), self::COLLECTION);
    }

    /**
     * The records of one name and type - what "is there already an A record for www" means.
     *
     * Answers a list because DNS permits several: two A records for one name is
     * round-robin, not a mistake.
     *
     * @return list<DomainRecord>
     */
    public function matching(string $domain, DomainRecordType $type, string $name): array
    {
        return $this->all($domain, $type, $name);
    }

    /**
     * Create a record.
     *
     * The record's named constructors emit only the fields their type carries - see
     * Entity\DomainRecord, which is where the type-dependent rules live.
     */
    public function create(string $domain, DomainRecord $record): DomainRecord
    {
        if (!$record->isManageable()) {
            throw new InvalidArgumentException(sprintf(
                'A %s record is maintained by BinaryLane and cannot be created.',
                $record->type->value
            ));
        }

        return DomainRecord::fromArray(
            $this->apiPost($this->path($domain), $record->toArray())->object('domain_record')
        );
    }

    /**
     * Change some fields of a record, leaving the rest alone.
     *
     * OMITTED KEEPS, EMPTY STRING CLEARS. That is the API's rule and it is the opposite of the
     * usual one, so the array is passed through as written rather than filtered:
     *
     *     update('example.com', 42, ['data' => '203.0.113.20']);   // just the address
     *     update('example.com', 42, ['tag' => '']);                // clear the tag
     *
     * The keys are the API's JSON property names, which is what makes the rule usable at all -
     * a typed object cannot express "this field is absent" and "this field is empty" as
     * different things without a third state for every field.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(string $domain, int $recordId, array $changes): DomainRecord
    {
        if ($changes === []) {
            throw new InvalidArgumentException(
                'An update with no changes would do nothing. To replace a record wholesale, use replace().'
            );
        }

        return DomainRecord::fromArray(
            $this->apiPut($this->path($domain, $recordId), $changes)->object('domain_record')
        );
    }

    /**
     * Replace a record with the one given - every field its type carries is sent.
     *
     * For when the record in hand is the record you mean to end up with. Fields the type does
     * not carry are not sent, so they keep whatever they had; on a record whose type has not
     * changed, that is nothing that matters.
     */
    public function replace(string $domain, int $recordId, DomainRecord $record): DomainRecord
    {
        return DomainRecord::fromArray(
            $this->apiPut($this->path($domain, $recordId), $record->toUpdateArray())->object('domain_record')
        );
    }

    /**
     * Delete a record. Answers 204 with nothing.
     */
    public function delete(string $domain, int $recordId): void
    {
        $this->apiDelete($this->path($domain, $recordId));
    }

    /**
     * Create a record, or update the existing one of the same name and type.
     *
     * THE OPERATION A DNS UPDATER ACTUALLY WANTS, and the one this API does not offer. It
     * costs one extra request - a filtered list - and it is where the ambiguity of DNS shows
     * up, so the behaviour is stated rather than assumed:
     *
     *  - no existing record of that name and type: created;
     *  - exactly one: updated in place, keeping its id;
     *  - MORE THAN ONE: refused. Several A records for one name is deliberate round-robin, and
     *    guessing which to change would silently break it. Delete the extras first, or use
     *    matching() and decide.
     */
    public function upsert(string $domain, DomainRecord $record): DomainRecord
    {
        $existing = $this->matching($domain, $record->type, $record->name);

        if (count($existing) > 1) {
            throw new InvalidArgumentException(sprintf(
                'There are %d %s records named "%s" in %s. Which one to change is a decision this '
                    . 'package will not make for you: several records of one name and type is '
                    . 'round-robin, not a duplicate. Use matching() and choose.',
                count($existing),
                $record->type->value,
                $record->name,
                $domain
            ));
        }

        $current = $existing[0] ?? null;

        if ($current === null || $current->id === null) {
            return $this->create($domain, $record);
        }

        return $this->replace($domain, $current->id, $record);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function filters(?DomainRecordType $type, ?string $name): array
    {
        return array_filter([
            'type' => $type?->value,
            'name' => $name,
        ], static fn (?string $value): bool => $value !== null);
    }

    private function path(string $domain, ?int $recordId = null): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($domain === '') {
            throw new InvalidArgumentException('A domain name is required.');
        }

        $path = 'domains/' . rawurlencode($domain) . '/records';

        if ($recordId === null) {
            return $path;
        }

        if ($recordId < 1) {
            throw new InvalidArgumentException(
                sprintf('A domain record id must be a positive integer; %d was given.', $recordId)
            );
        }

        return $path . '/' . $recordId;
    }
}
