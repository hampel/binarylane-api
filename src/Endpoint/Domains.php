<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Domain;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * DNS zones.
 *
 * `/v2/domains`
 *
 * ZONES ARE ADDRESSED BY NAME, NOT BY ID. `GET /v2/domains/example.com` - the id on a Domain
 * is informational and no endpoint takes it. That is convenient and it has one consequence
 * worth knowing: the name goes in the URL path, so a name that is not a domain produces a
 * request to a path that is not an endpoint.
 *
 * ADDING A ZONE HERE DOES NOT MAKE IT LIVE. The registrar decides which nameservers are
 * authoritative, and BinaryLane cannot change that. A zone added here serves nothing until
 * the domain is delegated to publicNameservers(); `Domain::isDelegatedTo()` is that check,
 * and `Domain::$currentNameservers` is what the domain really resolves to today.
 */
final class Domains extends Endpoint
{
    public const COLLECTION = 'domains';

    /**
     * One zone by name. Raises NotFoundException when the account does not have it.
     */
    public function get(string $name): Domain
    {
        return $this->apiObject($this->path($name), 'domain', Domain::fromArray(...));
    }

    /**
     * One zone by name, or null when the account does not have it.
     */
    public function find(string $name): ?Domain
    {
        return $this->apiFind($this->path($name), 'domain', Domain::fromArray(...));
    }

    /**
     * Whether the account holds this zone.
     */
    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * One page of the account's zones.
     *
     * @return Page<Domain>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('domains', self::COLLECTION, Domain::fromArray(...), $page, $perPage);
    }

    /**
     * Every zone, a page at a time.
     *
     * @return \Generator<int, Domain>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('domains', self::COLLECTION, Domain::fromArray(...), $perPage);
    }

    /**
     * How many zones the account holds, in one request that fetches none of them.
     */
    public function count(): int
    {
        return $this->apiCount('domains', self::COLLECTION);
    }

    /**
     * Add a zone.
     *
     * `$ipAddress` IS A CONVENIENCE WITH A SIDE EFFECT: given one, BinaryLane creates an A
     * record at the apex as part of adding the zone. Left null, the zone is created with only
     * the records BinaryLane puts on every zone.
     *
     * This does NOT delegate the domain - see the class note.
     */
    public function create(string $name, ?string $ipAddress = null): Domain
    {
        $name = $this->normalise($name);

        $payload = ['name' => $name];

        if ($ipAddress !== null) {
            $payload['ip_address'] = trim($ipAddress);
        }

        return Domain::fromArray($this->apiPost('domains', $payload)->requireObject('domain'));
    }

    /**
     * Delete a zone and every record in it.
     *
     * ANSWERS 204 WITH NOTHING - no action, nothing to await, and no undo. A domain still
     * delegated to BinaryLane stops resolving when this completes.
     */
    public function delete(string $name): void
    {
        $this->apiDelete($this->path($name));
    }

    /**
     * The nameservers a domain must be delegated to for zones here to serve.
     *
     * The list to give a registrar, and the list to compare `Domain::$currentNameservers`
     * against. Not paginated.
     *
     * @return list<string>
     */
    public function publicNameservers(): array
    {
        return Cast::strings($this->apiGet('domains/nameservers')->requireArray('local_nameservers'));
    }

    /**
     * Re-read the public nameservers for these domains.
     *
     * WHAT IT REFRESHES IS THE CACHE, NOT THE ZONE. `Domain::$currentNameservers` is read from
     * public DNS and cached; this asks BinaryLane to look again. It is what to call after
     * changing delegation at a registrar, when the domain still reports the old nameservers
     * here.
     *
     * @param  list<string>  $names
     */
    public function refreshNameserverCache(array $names): void
    {
        $names = array_values(array_map($this->normalise(...), $names));

        if ($names === []) {
            throw new InvalidArgumentException('Refreshing the nameserver cache needs at least one domain name.');
        }

        $this->apiPost('domains/refresh_nameserver_cache', ['domain_names' => $names]);
    }

    /**
     * The records of one zone, with the zone name bound in - for several calls against one
     * zone.
     *
     * `$binarylane->domains()->records('example.com')->create(DomainRecord::a('www', $ip))`
     */
    public function records(string $name): BoundDomainRecords
    {
        return new BoundDomainRecords(
            new DomainRecords($this->connection, $this->logger),
            $this->normalise($name)
        );
    }

    private function path(string $name): string
    {
        return 'domains/' . rawurlencode($this->normalise($name));
    }

    /**
     * A domain name, trimmed, lower-cased and stripped of a trailing dot.
     *
     * The trailing dot is the one worth handling: `example.com.` is how a zone is written in a
     * zone file and how several DNS tools report it, and it is not what this API's paths take.
     */
    private function normalise(string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));

        if ($name === '') {
            throw new InvalidArgumentException('A domain name is required.');
        }

        return $name;
    }
}
