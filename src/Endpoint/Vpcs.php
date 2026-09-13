<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\RouteEntry;
use Hampel\BinaryLane\Api\Entity\Vpc;
use Hampel\BinaryLane\Api\Entity\VpcMember;
use Hampel\BinaryLane\Api\Enum\ResourceType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Virtual private clouds.
 *
 * `/v2/vpcs`
 *
 * THE ONE PLACE IN THIS API WITH BOTH A PUT AND A PATCH, and the difference has teeth:
 *
 *  - PUT (`replace()`) requires the name and RESETS the route entries to whatever it is
 *    given. Called with just a name, it clears every route.
 *  - PATCH (`update()`) leaves out what it is not given. A null name keeps the name, null
 *    routes keep the routes, and an EMPTY LIST of routes clears them.
 *
 * update() is nearly always the one you want. replace() is here because the API has it.
 *
 * ROUTE ENTRIES CANNOT BE PATCHED INDIVIDUALLY. The specification says so outright: "to alter
 * a route entry submit the entire list of route entries you wish to save". addRoute() and
 * removeRoute() do the read-append-write for you - and, being two requests against a thing
 * somebody else might also be editing, they are not atomic. Vpc::withRoute() is the same
 * arithmetic if you would rather hold the read yourself.
 *
 * THE IP RANGE IS PERMANENT. Neither update carries the field, so it is chosen once at
 * creation and cannot be changed. Choose with room.
 */
final class Vpcs extends Endpoint
{
    public const COLLECTION = 'vpcs';

    public const MEMBERS = 'members';

    /**
     * One VPC by id.
     */
    public function get(int $id): Vpc
    {
        return $this->apiObject($this->path($id), 'vpc', Vpc::fromArray(...));
    }

    /**
     * One VPC by id, or null.
     */
    public function find(int $id): ?Vpc
    {
        return $this->apiFind($this->path($id), 'vpc', Vpc::fromArray(...));
    }

    /**
     * One page of the account's VPCs.
     *
     * @return Page<Vpc>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('vpcs', self::COLLECTION, Vpc::fromArray(...), $page, $perPage);
    }

    /**
     * Every VPC, a page at a time.
     *
     * @return \Generator<int, Vpc>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('vpcs', self::COLLECTION, Vpc::fromArray(...), $perPage);
    }

    public function count(): int
    {
        return $this->apiCount('vpcs', self::COLLECTION);
    }

    /**
     * Create a VPC.
     *
     * `$ipRange` IS PERMANENT once set - see the class note. Null accepts BinaryLane's default
     * of `10.240.0.0/16`.
     *
     * @param  list<RouteEntry>  $routes
     */
    public function create(string $name, ?string $ipRange = null, array $routes = []): Vpc
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A VPC needs a name.');
        }

        $payload = ['name' => $name];

        if ($ipRange !== null) {
            $payload['ip_range'] = trim($ipRange);
        }

        if ($routes !== []) {
            $payload['route_entries'] = $this->routes($routes);
        }

        return Vpc::fromArray($this->apiPost('vpcs', $payload)->requireObject('vpc'));
    }

    /**
     * Change a VPC, leaving alone what is not given - the PATCH.
     *
     * Null for either argument keeps what the VPC has. An EMPTY LIST of routes clears them
     * all, which is a different thing from null and is the only way to remove every route.
     *
     * @param  list<RouteEntry>|null  $routes
     */
    public function update(int $id, ?string $name = null, ?array $routes = null): Vpc
    {
        if ($name === null && $routes === null) {
            throw new InvalidArgumentException(
                'A VPC update with nothing to change would do nothing. Pass an empty route list to '
                    . 'clear the routes.'
            );
        }

        $payload = [];

        if ($name !== null) {
            $payload['name'] = trim($name);
        }

        if ($routes !== null) {
            $payload['route_entries'] = $this->routes($routes);
        }

        return Vpc::fromArray($this->apiPatch($this->path($id), $payload)->requireObject('vpc'));
    }

    /**
     * Replace a VPC's configuration - the PUT.
     *
     * RESETS THE ROUTES TO WHAT IT IS GIVEN. Called with a name and no routes, it removes
     * every route the VPC has. update() is the one that leaves things alone.
     *
     * @param  list<RouteEntry>  $routes
     */
    public function replace(int $id, string $name, array $routes = []): Vpc
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Replacing a VPC requires its name.');
        }

        return Vpc::fromArray(
            $this->apiPut($this->path($id), [
                'name' => $name,
                'route_entries' => $this->routes($routes),
            ])->requireObject('vpc')
        );
    }

    /**
     * Delete a VPC. Answers 204 with nothing.
     *
     * The servers in it are not deleted - they have to be moved out first, with
     * ServerActions::changeNetwork().
     */
    public function delete(int $id): void
    {
        $this->apiDelete($this->path($id));
    }

    /**
     * Add or replace one route entry, keeping the rest.
     *
     * TWO REQUESTS, AND NOT ATOMIC. The API replaces route sets wholesale, so this reads the
     * VPC and writes the whole set back - a route added by somebody else in between is lost.
     * Where that matters, read the VPC yourself, build the set with Vpc::withRoute(), and send
     * it with update().
     *
     * Replacement is by destination: two entries for one destination is a contradiction.
     */
    public function addRoute(int $id, RouteEntry $entry): Vpc
    {
        return $this->update($id, routes: $this->get($id)->withRoute($entry));
    }

    /**
     * Remove the route entry for a destination, keeping the rest. Two requests, not atomic -
     * see addRoute().
     */
    public function removeRoute(int $id, string $destination): Vpc
    {
        return $this->update($id, routes: $this->get($id)->withoutRoute($destination));
    }

    /**
     * Set the whole route table in one request.
     *
     * @param  list<RouteEntry>  $routes
     */
    public function setRoutes(int $id, array $routes): Vpc
    {
        return $this->update($id, routes: $routes);
    }

    /**
     * One page of the resources in a VPC.
     *
     * @return Page<VpcMember>
     */
    public function members(int $id, int $page = 1, ?int $perPage = null, ?ResourceType $type = null): Page
    {
        return $this->apiPaginate(
            $this->path($id) . '/members',
            self::MEMBERS,
            VpcMember::fromArray(...),
            $page,
            $perPage,
            $type === null ? [] : ['resource_type' => $type->value]
        );
    }

    /**
     * Every resource in a VPC, a page at a time.
     *
     * @return \Generator<int, VpcMember>
     */
    public function eachMember(int $id, ?int $perPage = null, ?ResourceType $type = null): \Generator
    {
        return $this->apiEach(
            $this->path($id) . '/members',
            self::MEMBERS,
            VpcMember::fromArray(...),
            $perPage,
            $type === null ? [] : ['resource_type' => $type->value]
        );
    }

    /**
     * The ids of the servers in a VPC.
     *
     * The member list reports `resource_id` as a STRING, unlike every other endpoint; this
     * hands back integers, which is what Servers::get() takes.
     *
     * @return list<int>
     */
    public function serverIds(int $id): array
    {
        $ids = [];

        foreach ($this->eachMember($id, null, ResourceType::Server) as $member) {
            $serverId = $member->serverId();

            if ($serverId !== null) {
                $ids[] = $serverId;
            }
        }

        return $ids;
    }

    /**
     * @param  list<RouteEntry>  $routes
     * @return list<array<string, mixed>>
     */
    private function routes(array $routes): array
    {
        return array_values(array_map(
            static fn (RouteEntry $entry): array => $entry->toArray(),
            $routes
        ));
    }

    private function path(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('A VPC id must be a positive integer; %d was given.', $id));
        }

        return 'vpcs/' . $id;
    }
}
