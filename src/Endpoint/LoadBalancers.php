<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\ForwardingRule;
use Hampel\BinaryLane\Api\Entity\HealthCheck;
use Hampel\BinaryLane\Api\Entity\LoadBalancer;
use Hampel\BinaryLane\Api\Entity\LoadBalancerAvailabilityOption;
use Hampel\BinaryLane\Api\Enum\LoadBalancerRuleProtocol;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Load balancers.
 *
 * `/v2/load_balancers`
 *
 * HTTP AND HTTPS ONLY. There is no TCP forwarding and no port mapping on a BinaryLane load
 * balancer - a forwarding rule is a protocol and nothing else. Anything that is not a web
 * protocol needs another approach entirely.
 *
 * REGION OR ANYCAST IS DECIDED AT CREATION AND CANNOT BE CHANGED. Naming a region creates a
 * regional load balancer; naming none creates an anycast one. The update endpoint has no
 * region field, so changing your mind means creating another and moving the servers.
 *
 * THERE ARE TWO WAYS TO CHANGE THE POOL AND THEY ARE NOT THE SAME:
 *
 *  - addServers() and removeServers() are ADDITIVE and subtractive, and are the ones to use.
 *  - update() REPLACES the whole load balancer - name, rules, health check and pool - so any
 *    of those omitted is reset rather than left alone. It is a PUT and it means it.
 *
 * The same is true of the forwarding rules: addForwardingRules() and removeForwardingRules()
 * against update().
 */
final class LoadBalancers extends Endpoint
{
    public const COLLECTION = 'load_balancers';

    /**
     * One load balancer by id.
     */
    public function get(int $id): LoadBalancer
    {
        return $this->apiObject($this->path($id), 'load_balancer', LoadBalancer::fromArray(...));
    }

    /**
     * One load balancer by id, or null.
     */
    public function find(int $id): ?LoadBalancer
    {
        return $this->apiFind($this->path($id), 'load_balancer', LoadBalancer::fromArray(...));
    }

    /**
     * One page of the account's load balancers.
     *
     * @param  string|null  $name  restricts the result to the one with this hostname
     * @return Page<LoadBalancer>
     */
    public function list(int $page = 1, ?int $perPage = null, ?string $name = null): Page
    {
        return $this->apiPaginate(
            'load_balancers',
            self::COLLECTION,
            LoadBalancer::fromArray(...),
            $page,
            $perPage,
            $name === null ? [] : ['name' => $name]
        );
    }

    /**
     * Every load balancer, a page at a time.
     *
     * @return \Generator<int, LoadBalancer>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('load_balancers', self::COLLECTION, LoadBalancer::fromArray(...), $perPage);
    }

    public function count(): int
    {
        return $this->apiCount('load_balancers', self::COLLECTION);
    }

    /**
     * The one with this hostname, or null.
     */
    public function findByName(string $name): ?LoadBalancer
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A load balancer name is required to look one up by it.');
        }

        return $this->list(name: $name)->first();
    }

    /**
     * What kinds of load balancer can be created, where, and at what price.
     *
     * Not paginated. An option with `anycast` true has a null region list, which means
     * everywhere rather than nowhere.
     *
     * @return list<LoadBalancerAvailabilityOption>
     */
    public function availability(): array
    {
        return Cast::objects(
            $this->apiGet('load_balancers/availability')->requireArray('load_balancer_availability_options'),
            LoadBalancerAvailabilityOption::fromArray(...)
        );
    }

    /**
     * Create a load balancer.
     *
     * `$region` NULL CREATES AN ANYCAST LOAD BALANCER, which is a different product rather
     * than a default - see the class note, and availability() for what each costs.
     *
     * @param  list<LoadBalancerRuleProtocol>  $protocols  which traffic to forward. Empty
     *                                                     creates one with no rules, which
     *                                                     forwards nothing
     * @param  list<int>  $serverIds  the initial pool
     */
    public function create(
        string $name,
        ?string $region = null,
        array $protocols = [],
        array $serverIds = [],
        ?HealthCheck $healthCheck = null,
    ): LoadBalancer {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A load balancer needs a hostname.');
        }

        $payload = ['name' => $name];

        if ($region !== null) {
            $payload['region'] = trim($region);
        }

        if ($protocols !== []) {
            $payload['forwarding_rules'] = $this->rules($protocols);
        }

        if ($serverIds !== []) {
            $payload['server_ids'] = array_values($serverIds);
        }

        if ($healthCheck !== null) {
            $payload['health_check'] = $healthCheck->toArray();
        }

        return LoadBalancer::fromArray($this->apiPost('load_balancers', $payload)->requireObject('load_balancer'));
    }

    /**
     * Replace a load balancer's configuration.
     *
     * A FULL REPLACEMENT. Whatever is not given is RESET rather than kept: omitting
     * `$serverIds` empties the pool, and omitting `$protocols` removes the forwarding rules.
     * That is what the PUT means, and it is why addServers() and addForwardingRules() exist.
     *
     * The safe way to change one thing is to read the load balancer first and pass the rest
     * back - or to use the additive operations, which do not have this shape.
     *
     * @param  list<LoadBalancerRuleProtocol>  $protocols
     * @param  list<int>  $serverIds
     */
    public function update(
        int $id,
        string $name,
        array $protocols = [],
        array $serverIds = [],
        ?HealthCheck $healthCheck = null,
    ): LoadBalancer {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A load balancer update requires its hostname.');
        }

        $payload = [
            'name' => $name,
            'forwarding_rules' => $this->rules($protocols),
            'server_ids' => array_values($serverIds),
        ];

        if ($healthCheck !== null) {
            $payload['health_check'] = $healthCheck->toArray();
        }

        return LoadBalancer::fromArray($this->apiPut($this->path($id), $payload)->requireObject('load_balancer'));
    }

    /**
     * Add servers to the pool, leaving the ones already there.
     *
     * Answers 204 with nothing.
     *
     * @param  list<int>  $serverIds
     */
    public function addServers(int $id, array $serverIds): void
    {
        $this->apiPost($this->path($id) . '/servers', ['server_ids' => $this->serverIds($serverIds)]);
    }

    /**
     * Take servers out of the pool.
     *
     * A DELETE THAT CARRIES A BODY, which is unusual enough that some HTTP clients make it
     * awkward; Connection handles it.
     *
     * REMOVING THE LAST SERVER LEAVES A LOAD BALANCER FORWARDING TO NOTHING. It stays up and
     * answers every request with an error, which looks like a network fault rather than an
     * empty pool.
     *
     * @param  list<int>  $serverIds
     */
    public function removeServers(int $id, array $serverIds): void
    {
        $this->apiDelete($this->path($id) . '/servers', [], ['server_ids' => $this->serverIds($serverIds)]);
    }

    /**
     * Add forwarding rules, leaving the existing ones.
     *
     * @param  list<LoadBalancerRuleProtocol>  $protocols
     */
    public function addForwardingRules(int $id, array $protocols): void
    {
        if ($protocols === []) {
            throw new InvalidArgumentException('Adding forwarding rules needs at least one protocol.');
        }

        $this->apiPost($this->path($id) . '/forwarding_rules', ['forwarding_rules' => $this->rules($protocols)]);
    }

    /**
     * Remove forwarding rules.
     *
     * REMOVING THE LAST RULE STOPS THE LOAD BALANCER FORWARDING ANYTHING, without changing its
     * status - it is still `active` and still answers nothing.
     *
     * @param  list<LoadBalancerRuleProtocol>  $protocols
     */
    public function removeForwardingRules(int $id, array $protocols): void
    {
        if ($protocols === []) {
            throw new InvalidArgumentException('Removing forwarding rules needs at least one protocol.');
        }

        $this->apiDelete($this->path($id) . '/forwarding_rules', [], ['forwarding_rules' => $this->rules($protocols)]);
    }

    /**
     * Cancel a load balancer.
     *
     * A CANCELLATION, LIKE A SERVER'S - the specification calls it "Cancel an Existing Load
     * Balancer" rather than delete. Answers 204 with nothing; the servers in its pool are not
     * touched.
     */
    public function cancel(int $id): void
    {
        $this->apiDelete($this->path($id));
    }

    /**
     * @param  list<LoadBalancerRuleProtocol>  $protocols
     * @return list<array<string, mixed>>
     */
    private function rules(array $protocols): array
    {
        return array_values(array_map(
            static fn (LoadBalancerRuleProtocol $protocol): array => (new ForwardingRule($protocol))->toArray(),
            $protocols
        ));
    }

    /**
     * @param  list<int>  $serverIds
     * @return list<int>
     */
    private function serverIds(array $serverIds): array
    {
        if ($serverIds === []) {
            throw new InvalidArgumentException('At least one server id is needed.');
        }

        foreach ($serverIds as $serverId) {
            if ($serverId < 1) {
                throw new InvalidArgumentException(
                    sprintf('A server id must be a positive integer; %d was given.', $serverId)
                );
            }
        }

        return array_values($serverIds);
    }

    private function path(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException(
                sprintf('A load balancer id must be a positive integer; %d was given.', $id)
            );
        }

        return 'load_balancers/' . $id;
    }
}
