<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\HealthCheck;
use Hampel\BinaryLane\Api\Entity\LoadBalancer;
use Hampel\BinaryLane\Api\Entity\RouteEntry;
use Hampel\BinaryLane\Api\Entity\Vpc;
use Hampel\BinaryLane\Api\Enum\HealthCheckProtocol;
use Hampel\BinaryLane\Api\Enum\LoadBalancerRuleProtocol;
use Hampel\BinaryLane\Api\Enum\ResourceType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Load balancers and VPCs - the two endpoints whose set-shaped fields replace rather than
 * merge.
 */
final class NetworkingTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function loadBalancerRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 9,
            'name' => 'lb01.example.test',
            'ip' => '203.0.113.200',
            'status' => 'active',
            'created_at' => '2026-05-01T00:00:00Z',
            'forwarding_rules' => [['entry_protocol' => 'https']],
            'health_check' => ['protocol' => 'https', 'path' => '/healthz'],
            'region' => ['slug' => 'syd', 'name' => 'Sydney'],
            'server_ids' => [1234, 5678],
        ];
    }

    // ------------------------------------------------------------------------------------
    // Load balancers
    // ------------------------------------------------------------------------------------

    public function testItReadsALoadBalancer(): void
    {
        $this->client->pushJson(200, ['load_balancer' => $this->loadBalancerRow()]);

        $lb = $this->binarylane()->loadBalancers()->get(9);

        $this->assertSame('/v2/load_balancers/9', $this->sentPath());
        $this->assertSame('203.0.113.200', $lb->ip);
        $this->assertTrue($lb->isAvailable());
        $this->assertTrue($lb->hasServer(1234));
        $this->assertFalse($lb->isEmpty());
        $this->assertFalse($lb->isAnycast());
    }

    /**
     * A null region means anycast, not "unknown".
     */
    public function testANullRegionMeansAnycast(): void
    {
        $this->client->pushJson(200, ['load_balancer' => $this->loadBalancerRow(['region' => null])]);

        $this->assertTrue($this->binarylane()->loadBalancers()->get(9)->isAnycast());
    }

    public function testCreatingWithoutARegionCreatesAnAnycastLoadBalancer(): void
    {
        $this->client->pushJson(200, ['load_balancer' => $this->loadBalancerRow(['region' => null])]);

        $this->binarylane()->loadBalancers()->create('lb01.example.test', protocols: [LoadBalancerRuleProtocol::Https]);

        $this->assertArrayNotHasKey('region', $this->sentBody());
        $this->assertSame([['entry_protocol' => 'https']], $this->sentBody()['forwarding_rules']);
    }

    public function testCreatingWithARegionSendsIt(): void
    {
        $this->client->pushJson(200, ['load_balancer' => $this->loadBalancerRow()]);

        $this->binarylane()->loadBalancers()->create(
            'lb01.example.test',
            'syd',
            [LoadBalancerRuleProtocol::Http, LoadBalancerRuleProtocol::Https],
            [1234],
            new HealthCheck(HealthCheckProtocol::Https, '/healthz'),
        );

        $body = $this->sentBody();

        $this->assertSame('syd', $body['region']);
        $this->assertSame([1234], $body['server_ids']);
        $this->assertSame(['protocol' => 'https', 'path' => '/healthz'], $body['health_check']);
    }

    /**
     * The PUT means it: anything omitted is reset, so an update with no pool empties the pool.
     */
    public function testUpdateResetsWhatItIsNotGiven(): void
    {
        $this->client->pushJson(200, ['load_balancer' => $this->loadBalancerRow(['server_ids' => []])]);

        $this->binarylane()->loadBalancers()->update(9, 'lb01.example.test');

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame([
            'name' => 'lb01.example.test',
            'forwarding_rules' => [],
            'server_ids' => [],
        ], $this->sentBody());
    }

    public function testAddingServersIsAdditiveAndDoesNotTouchTheRest(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->loadBalancers()->addServers(9, [5678]);

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/load_balancers/9/servers', $this->sentPath());
        $this->assertSame(['server_ids' => [5678]], $this->sentBody());
    }

    /**
     * Removing servers is a DELETE carrying a body, which is unusual enough to pin down.
     */
    public function testRemovingServersIsADeleteWithABody(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->loadBalancers()->removeServers(9, [5678]);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame(['server_ids' => [5678]], $this->sentBody());
    }

    public function testRemovingNoServersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->loadBalancers()->removeServers(9, []);
    }

    public function testForwardingRulesCanBeAddedAndRemoved(): void
    {
        $this->client->pushRaw(204, '');
        $this->binarylane()->loadBalancers()->addForwardingRules(9, [LoadBalancerRuleProtocol::Http]);
        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame(['forwarding_rules' => [['entry_protocol' => 'http']]], $this->sentBody());

        $this->client->pushRaw(204, '');
        $this->binarylane()->loadBalancers()->removeForwardingRules(9, [LoadBalancerRuleProtocol::Http]);
        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame(['forwarding_rules' => [['entry_protocol' => 'http']]], $this->sentBody());
    }

    public function testAvailabilityIsNotPaginated(): void
    {
        $this->client->pushJson(200, ['load_balancer_availability_options' => [
            ['anycast' => false, 'price_monthly' => 20.0, 'price_hourly' => 0.03, 'regions' => ['syd', 'mel']],
            ['anycast' => true, 'price_monthly' => 50.0, 'price_hourly' => 0.08, 'regions' => null],
        ]]);

        $options = $this->binarylane()->loadBalancers()->availability();

        $this->assertCount(2, $options);
        $this->assertTrue($options[0]->isAvailableIn('syd'));
        $this->assertFalse($options[0]->isAvailableIn('per'));
        $this->assertTrue($options[1]->isAvailableIn('per'), 'an anycast option is available everywhere');
    }

    /**
     * `both` removes a failing server from that protocol's pool only, which is the opposite of
     * what the word suggests.
     */
    public function testTheBothHealthCheckIsPerProtocol(): void
    {
        $lb = LoadBalancer::fromArray($this->loadBalancerRow([
            'health_check' => ['protocol' => 'both', 'path' => '/'],
        ]));

        $this->assertTrue($lb->healthCheck?->isPerProtocol());
    }

    public function testCancelIsADelete(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->loadBalancers()->cancel(9);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame('/v2/load_balancers/9', $this->sentPath());
    }

    // ------------------------------------------------------------------------------------
    // VPCs
    // ------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function vpcRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 3,
            'name' => 'production',
            'ip_range' => '10.240.0.0/16',
            'route_entries' => [
                ['router' => '10.240.0.5', 'destination' => '0.0.0.0/0', 'description' => 'default via the gateway'],
            ],
        ];
    }

    public function testItReadsAVpcAndItsRoutes(): void
    {
        $this->client->pushJson(200, ['vpc' => $this->vpcRow()]);

        $vpc = $this->binarylane()->vpcs()->get(3);

        $this->assertSame('/v2/vpcs/3', $this->sentPath());
        $this->assertSame('10.240.0.0/16', $vpc->ipRange);
        $this->assertTrue($vpc->hasDefaultRoute());
        $this->assertTrue($vpc->routes('0.0.0.0/0'));
    }

    /**
     * The PATCH leaves out what it is not given; the PUT resets it.
     */
    public function testUpdateIsAPatchThatLeavesOmittedFieldsAlone(): void
    {
        $this->client->pushJson(200, ['vpc' => $this->vpcRow(['name' => 'renamed'])]);

        $this->binarylane()->vpcs()->update(3, 'renamed');

        $this->assertSame('PATCH', $this->sentMethod());
        $this->assertSame(['name' => 'renamed'], $this->sentBody());
    }

    public function testReplaceIsAPutThatClearsTheRoutesItIsNotGiven(): void
    {
        $this->client->pushJson(200, ['vpc' => $this->vpcRow(['route_entries' => []])]);

        $this->binarylane()->vpcs()->replace(3, 'production');

        $this->assertSame('PUT', $this->sentMethod());
        $this->assertSame(['name' => 'production', 'route_entries' => []], $this->sentBody());
    }

    /**
     * An empty list clears the routes; null leaves them alone. They are different requests.
     */
    public function testAnEmptyRouteListClearsTheRoutesAndNullDoesNot(): void
    {
        $this->client->pushJson(200, ['vpc' => $this->vpcRow(['route_entries' => []])]);

        $this->binarylane()->vpcs()->update(3, routes: []);

        $this->assertSame(['route_entries' => []], $this->sentBody());
    }

    public function testAnUpdateWithNothingToChangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->vpcs()->update(3);
    }

    /**
     * The API cannot patch individual route entries, so adding one is a read and a whole-set
     * write.
     */
    public function testAddingARouteReadsThenWritesTheWholeSet(): void
    {
        $this->client
            ->pushJson(200, ['vpc' => $this->vpcRow()])
            ->pushJson(200, ['vpc' => $this->vpcRow()]);

        $this->binarylane()->vpcs()->addRoute(3, RouteEntry::to('10.0.0.0/8', '10.240.0.9'));

        $this->assertCount(2, $this->client->requests);
        $this->assertSame('PATCH', $this->sentMethod());

        $body = $this->sentBody();

        $this->assertIsArray($body['route_entries']);
        $this->assertCount(2, $body['route_entries'], 'the existing route is sent back with the new one');
    }

    /**
     * Two entries for one destination is a contradiction, so a route is replaced by
     * destination rather than appended.
     */
    public function testARouteForAnExistingDestinationReplacesIt(): void
    {
        $vpc = Vpc::fromArray($this->vpcRow());

        $entries = $vpc->withRoute(RouteEntry::to('0.0.0.0/0', '10.240.0.99'));

        $this->assertCount(1, $entries);
        $this->assertSame('10.240.0.99', $entries[0]->router);
    }

    public function testARouteCanBeRemovedByDestination(): void
    {
        $vpc = Vpc::fromArray($this->vpcRow());

        $this->assertSame([], $vpc->withoutRoute('0.0.0.0/0'));
        $this->assertCount(1, $vpc->withoutRoute('192.168.0.0/16'));
    }

    public function testARouteEntryNeedsBothEnds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RouteEntry::to('10.0.0.0/8', '   ');
    }

    /**
     * The member list reports resource_id as a string, unlike every other endpoint.
     */
    public function testVpcMemberIdsAreStringsAndAreConvertedForUse(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['name' => 'vps01', 'resource_type' => 'server', 'resource_id' => '1234', 'created_at' => '2026-05-01T00:00:00Z'],
            ['name' => 'lb01', 'resource_type' => 'load-balancer', 'resource_id' => '9'],
        ], 'members'));

        $page = $this->binarylane()->vpcs()->members(3);

        $this->assertCount(2, $page);

        $member = $page->first();

        $this->assertNotNull($member);
        $this->assertSame('1234', $member->resourceId, 'a string on the wire');
        $this->assertSame(1234, $member->serverId(), 'an integer for everything else');
        $this->assertTrue($member->isServer());
    }

    public function testEachLoadBalancerCanBeFilteredByName(): void
    {
        // Unlike the server hostname filter, the specification does not say a name matches at
        // most one, so each() carries the filter list() has.
        $this->client->pushJson(200, $this->collection([
            ['id' => 9, 'name' => 'lb01.example.test'],
            ['id' => 10, 'name' => 'lb01.example.test'],
        ], 'load_balancers'));

        $found = iterator_to_array($this->binarylane()->loadBalancers()->each(name: 'lb01.example.test'), false);

        $this->assertCount(2, $found);
        $this->assertStringContainsString('name=lb01.example.test', urldecode($this->sentQuery()));
    }

    public function testServerIdsFiltersTheMembersByType(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['name' => 'vps01', 'resource_type' => 'server', 'resource_id' => '1234'],
        ], 'members'));

        $ids = $this->binarylane()->vpcs()->serverIds(3);

        $this->assertSame([1234], $ids);
        $this->assertStringContainsString('resource_type=server', urldecode($this->sentQuery()));
    }

    public function testMembersCanBeFilteredByResourceType(): void
    {
        $this->client->pushJson(200, $this->collection([], 'members'));

        $this->binarylane()->vpcs()->members(3, type: ResourceType::LoadBalancer);

        $this->assertStringContainsString('resource_type=load-balancer', urldecode($this->sentQuery()));
    }

    public function testAVpcIdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->vpcs()->get(0);
    }
}
