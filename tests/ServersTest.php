<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Enum\NetworkType;
use Hampel\BinaryLane\Api\Enum\ServerStatus;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\CreateServer;
use Hampel\BinaryLane\Api\Request\SizeOptions;

final class ServersTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function serverRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1234,
            'name' => 'vps01.example.test',
            'memory' => 4096,
            'vcpus' => 2,
            'disk' => 80,
            'vpc_id' => null,
            'created_at' => '2026-01-05T03:04:05Z',
            'status' => 'active',
            'backup_ids' => [9001, 9002],
            'features' => ['ipv6'],
            'size_slug' => 'std-2vcpu',
            'permalink' => 'brave-lemur',
            'password_change_supported' => true,
            'is_under_maintenance' => false,
            'region' => ['slug' => 'syd', 'name' => 'Sydney', 'sizes' => ['std-2vcpu'], 'available' => true],
            'selected_size_options' => [
                'daily_backups' => 2,
                'weekly_backups' => 1,
                'monthly_backups' => 0,
                'offsite_backups' => false,
                'ipv4_addresses' => 1,
                'memory' => 4096,
                'disk' => 80,
                'transfer' => 1.0,
            ],
            'networks' => [
                'v4' => [
                    ['ip_address' => '203.0.113.10', 'type' => 'public', 'netmask' => '255.255.255.0', 'gateway' => '203.0.113.1'],
                    ['ip_address' => '10.20.30.40', 'type' => 'private', 'netmask' => '255.255.0.0'],
                ],
                'v6' => [
                    ['ip_address' => '2001:db8::1', 'type' => 'public', 'netmask' => 64],
                ],
                'port_blocking' => true,
                'recent_ddos' => false,
                'mac_address' => '00:16:3e:00:00:01',
            ],
            'disks' => [
                ['id' => 1, 'size_gigabytes' => 80.0, 'primary' => true, 'description' => 'Primary'],
                ['id' => 2, 'size_gigabytes' => 20.5, 'primary' => false, 'description' => 'Data'],
            ],
        ];
    }

    public function testItReadsAServer(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow()]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertSame('/v2/servers/1234', $this->sentPath());
        $this->assertSame(1234, $server->id);
        $this->assertSame('vps01.example.test', $server->name);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertTrue($server->isInService());
        $this->assertTrue($server->isActionable());
        $this->assertFalse($server->isInVpc());
        $this->assertSame('syd', $server->regionSlug());
        $this->assertSame([9001, 9002], $server->backupIds);
    }

    /**
     * The wire value is the string `off`, which YAML 1.1 parsers read as boolean false.
     */
    public function testItRecognisesAnExplicitlyPoweredOffServer(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(['status' => 'off'])]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertSame(ServerStatus::Off, $server->status);
        $this->assertSame('off', $server->status->value);
        $this->assertTrue($server->isExplicitlyPoweredOff());
        $this->assertTrue($server->permitsPowerOn());
        $this->assertFalse($server->isCancelled());
    }

    /**
     * THE FINDING THAT COST THIS PACKAGE A METHOD. Measured on 13 September 2026: a server
     * that was genuinely powered off reported `active`, exactly as its running neighbours
     * did, and nothing else in the payload carries a power state.
     *
     * So `active` cannot be read as "running" - the old isRunning() did, and was wrong on
     * precisely the server it mattered for. The payload can say a server is in service; it
     * cannot say whether the operating system is up, and ServerActions::isRunning() exists
     * because of that.
     */
    public function testAnActiveServerIsNotNecessarilyPoweredOn(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(['status' => 'active'])]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertTrue($server->isInService(), 'provisioned and paid for');
        $this->assertFalse(
            $server->isExplicitlyPoweredOff(),
            'and the API did not say it was off - which is not the same as it being on'
        );
        $this->assertFalse($server->isDefinitelyStopped(), 'the payload cannot settle it either way');
        $this->assertTrue($server->permitsPowerOn(), 'nothing in the state forbids a power-on');

        $this->assertFalse(
            (new \ReflectionClass(Server::class))->hasMethod('isRunning'),
            'Server must not offer isRunning(): the payload cannot answer it, and a method that '
                . 'looks like it can is worse than no method at all'
        );
    }

    /**
     * `archive` is also powered off, and cannot be powered back on until the cancellation is
     * reverted.
     */
    public function testACancelledServerCannotSimplyBePoweredOn(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow([
            'status' => 'archive',
            'cancelled_at' => '2026-09-01T00:00:00Z',
        ])]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertTrue($server->isCancelled());
        $this->assertTrue($server->isDefinitelyStopped());
        $this->assertFalse($server->permitsPowerOn());
    }

    public function testAServerUnderMaintenanceIsNotActionable(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(['is_under_maintenance' => true])]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertFalse($server->isActionable());
        $this->assertFalse($server->permitsPowerOn());
    }

    public function testItSortsTheAddressesByReachabilityRatherThanByFamily(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow()]);

        $networks = $this->binarylane()->servers()->get(1234)->networks;

        $this->assertNotNull($networks);
        $this->assertCount(1, $networks->publicV4());
        $this->assertCount(1, $networks->privateV4());
        $this->assertSame('203.0.113.10', $networks->primaryPublicAddress()?->ipAddress);
        $this->assertTrue($networks->hasPublicAddress());
        $this->assertSame(NetworkType::Private, $networks->privateV4()[0]->type);
    }

    /**
     * The netmask is `oneOf: [integer, string]` - dotted for IPv4, a prefix length for IPv6.
     */
    public function testItNormalisesBothShapesOfNetmask(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow()]);

        $networks = $this->binarylane()->servers()->get(1234)->networks;

        $this->assertNotNull($networks);
        $this->assertSame('255.255.255.0', $networks->v4[0]->netmask);
        $this->assertSame(24, $networks->v4[0]->prefixLength());
        $this->assertSame('203.0.113.10/24', $networks->v4[0]->cidr());
        $this->assertSame(64, $networks->v6[0]->netmask);
        $this->assertSame(64, $networks->v6[0]->prefixLength());
        $this->assertTrue($networks->v6[0]->isIpv6());
        $this->assertTrue($networks->v4[0]->isIpv4());
    }

    public function testItSeparatesThePrimaryDiskFromTheRest(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow()]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertSame(1, $server->primaryDisk()?->id);
        $this->assertCount(1, $server->additionalDisks());
        $this->assertSame(2, $server->additionalDisks()[0]->id);
    }

    public function testItReadsWhatTheServerHasRatherThanWhatTheSizeIncludes(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow()]);

        $options = $this->binarylane()->servers()->get(1234)->selectedSizeOptions;

        $this->assertNotNull($options);
        $this->assertSame(4096, $options->memory);
        $this->assertSame(3, $options->backupSlots());
        $this->assertTrue($options->hasBackups());
        $this->assertSame(1000.0, $options->transferGigabytes());
    }

    public function testListPaginates(): void
    {
        $this->client->pushJson(200, $this->collection([$this->serverRow()], 'servers', total: 42));

        $page = $this->binarylane()->servers()->list(page: 2, perPage: 50);

        $this->assertSame(42, $page->total);
        $this->assertSame(2, $page->currentPage);
        $this->assertStringContainsString('per_page=50', $this->sentQuery());
        $this->assertStringContainsString('page=2', $this->sentQuery());
    }

    public function testFindByHostnameUsesTheFilterTheApiProvides(): void
    {
        $this->client->pushJson(200, $this->collection([$this->serverRow()], 'servers'));

        $server = $this->binarylane()->servers()->findByHostname('vps01.example.test');

        $this->assertSame(1234, $server?->id);
        $this->assertStringContainsString('hostname=vps01.example.test', urldecode($this->sentQuery()));
    }

    public function testFindByHostnameAnswersNullWhenNothingMatches(): void
    {
        $this->client->pushJson(200, $this->collection([], 'servers'));

        $this->assertNull($this->binarylane()->servers()->findByHostname('nothing.example.test'));
    }

    public function testCreateReturnsTheServerAndTheActionsStillBuildingIt(): void
    {
        $this->client->pushJson(200, [
            'server' => $this->serverRow(['status' => 'new']),
            'links' => ['actions' => [
                ['id' => 5001, 'rel' => 'create', 'href' => 'https://api.binarylane.com.au/v2/actions/5001'],
            ]],
        ]);

        $created = $this->binarylane()->servers()->create(
            CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd')->withName('vps01.example.test')
        );

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/servers', $this->sentPath());
        $this->assertSame([
            'size' => 'std-2vcpu',
            'image' => 'ubuntu-24-04-lts',
            'region' => 'syd',
            'name' => 'vps01.example.test',
        ], $this->sentBody());

        $this->assertSame(1234, $created->id());
        $this->assertSame(ServerStatus::New, $created->server->status);
        $this->assertSame([5001], $created->actionIds());
        $this->assertTrue($created->hasActions());
    }

    /**
     * Null deploys the account's default keys; an empty array deploys none. The request has to
     * keep those apart.
     */
    public function testAnEmptySshKeyListIsSentAndIsNotTheSameAsOmittingIt(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(), 'links' => ['actions' => []]]);

        $this->binarylane()->servers()->create(
            CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd')->withoutSshKeys()
        );

        $this->assertArrayHasKey('ssh_keys', $this->sentBody());
        $this->assertSame([], $this->sentBody()['ssh_keys']);
    }

    public function testAnImageIdIsSentAsAnIntegerRatherThanAString(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(), 'links' => ['actions' => []]]);

        $this->binarylane()->servers()->create(CreateServer::of('std-2vcpu', 9001, 'syd'));

        $this->assertSame(9001, $this->sentBody()['image']);
    }

    public function testCreateSendsTheOptionsItWasGiven(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(), 'links' => ['actions' => []]]);

        $this->binarylane()->servers()->create(
            CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd')
                ->withOptions(SizeOptions::none()->withMemory(8192)->withDailyBackups(7))
        );

        $this->assertSame(['memory' => 8192, 'daily_backups' => 7], $this->sentBody()['options']);
    }

    public function testAnEmptyOptionsObjectIsNotSentAtAll(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(), 'links' => ['actions' => []]]);

        $this->binarylane()->servers()->create(
            CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd')->withOptions(SizeOptions::none())
        );

        $this->assertArrayNotHasKey('options', $this->sentBody());
    }

    public function testCancelIsADeleteThatAnswersWithNothing(): void
    {
        $this->client->pushRaw(204, '');

        $this->binarylane()->servers()->cancel(1234, 'no longer needed');

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame('/v2/servers/1234', $this->sentPath());
        $this->assertStringContainsString('reason=no%20longer%20needed', $this->sentQuery());
    }

    public function testCancelRefusesAnOverlongReason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->servers()->cancel(1234, str_repeat('x', 251));
    }

    public function testAServerIdMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');

        $this->binarylane()->servers()->get(0);
    }

    public function testTheFirewallRulesAreNotPaginated(): void
    {
        $this->client->pushJson(200, ['firewall_rules' => [
            [
                'source_addresses' => ['0.0.0.0/0'],
                'destination_addresses' => ['203.0.113.10'],
                'protocol' => 'tcp',
                'action' => 'accept',
                'destination_ports' => ['443'],
            ],
        ]]);

        $rules = $this->binarylane()->servers()->advancedFirewallRules(1234);

        $this->assertCount(1, $rules);
        $this->assertFalse($rules[0]->matchesAllPorts());
        $this->assertTrue($rules[0]->portsAreMeaningful());
        $this->assertFalse($rules[0]->drops());
    }

    /**
     * A null or empty port list matches every port, which on a drop rule is a large
     * difference.
     */
    public function testARuleWithNoPortsMatchesEveryPort(): void
    {
        $this->client->pushJson(200, ['firewall_rules' => [
            [
                'source_addresses' => ['0.0.0.0/0'],
                'destination_addresses' => ['203.0.113.10'],
                'protocol' => 'tcp',
                'action' => 'drop',
                'destination_ports' => null,
            ],
        ]]);

        $rules = $this->binarylane()->servers()->advancedFirewallRules(1234);

        $this->assertTrue($rules[0]->matchesAllPorts());
        $this->assertTrue($rules[0]->drops());
        $this->assertStringContainsString('on any port', $rules[0]->describe());
    }

    public function testTheFleetAlertCheckIsOneRequestAnswringIdsOnly(): void
    {
        $this->client->pushJson(200, ['server_ids' => [1234, 5678]]);

        $ids = $this->binarylane()->servers()->serversWithExceededAlerts();

        $this->assertSame([1234, 5678], $ids);
        $this->assertSame('/v2/servers/threshold_alerts', $this->sentPath());
    }

    /**
     * The only endpoint in the specification whose body is the object itself.
     */
    public function testUserDataHasNoEnvelope(): void
    {
        $this->client->pushJson(200, ['user_data' => "#cloud-config\npackages:\n  - nginx\n"]);

        $userData = $this->binarylane()->servers()->userData(1234);

        $this->assertFalse($userData->isEmpty());
        $this->assertTrue($userData->isCloudConfig());
        $this->assertSame('/v2/servers/1234/user_data', $this->sentPath());
    }

    public function testUserDataIsWithheldFromDebugOutput(): void
    {
        $this->client->pushJson(200, ['user_data' => 'password: hunter2']);

        $userData = $this->binarylane()->servers()->userData(1234);

        $this->assertStringNotContainsString('hunter2', print_r($userData, true));
        $this->assertStringContainsString('withheld', print_r($userData, true));
    }

    public function testConsoleUrlsAreWithheldFromDebugOutput(): void
    {
        $this->client->pushJson(200, ['console' => [
            'iframe' => 'https://console.example.test/?token=secret-token',
            'browser' => 'https://console.example.test/full?token=secret-token',
            'width' => 1024,
            'height' => 768,
            'expiry' => '2026-09-12T02:00:00Z',
        ]]);

        $console = $this->binarylane()->servers()->console(1234);

        $this->assertSame(1024, $console->width);
        $this->assertStringNotContainsString('secret-token', print_r($console, true));
        $this->assertTrue($console->isExpired(new \DateTimeImmutable('2026-09-12T03:00:00Z')));
        $this->assertFalse($console->isExpired(new \DateTimeImmutable('2026-09-12T01:00:00Z')));
    }

    public function testBackupsArePaginated(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['id' => 9001, 'name' => 'vps01 daily', 'type' => 'backup', 'status' => 'available',
                'backup_info' => ['type' => 'daily', 'server_id' => 1234, 'locked' => false, 'iso' => false,
                    'offsite' => true, 'backup_disks' => [['id' => 1, 'size_gigabytes' => 12.5, 'min_disk_size' => 80]]]],
        ], 'backups', total: 3));

        $page = $this->binarylane()->servers()->backups(1234);

        $this->assertSame(3, $page->total);
        $this->assertSame('/v2/servers/1234/backups', $this->sentPath());

        $backup = $page->first();

        $this->assertInstanceOf(Image::class, $backup);
        $this->assertTrue($backup->isBackup());

        $info = $backup->backupInfo;

        $this->assertNotNull($info);
        $this->assertTrue($info->isRestorable());
        $this->assertSame(80, $info->minDiskSize());
        $this->assertFalse($info->fitsIn(40));
        $this->assertTrue($info->fitsIn(80));
    }

    /**
     * An ISO backup cannot be restored at all, whatever the disk size.
     */
    public function testAnIsoBackupIsNotRestorable(): void
    {
        $this->client->pushJson(200, $this->collection([
            ['id' => 9003, 'name' => 'rescue.iso', 'type' => 'backup',
                'backup_info' => ['type' => 'temporary', 'server_id' => 1234, 'iso' => true, 'locked' => false,
                    'offsite' => false, 'backup_disks' => []]],
        ], 'backups'));

        $backup = $this->binarylane()->servers()->backups(1234)->first();

        $this->assertInstanceOf(Image::class, $backup);

        $info = $backup->backupInfo;

        $this->assertNotNull($info);
        $this->assertFalse($info->isRestorable());
        $this->assertFalse($info->fitsIn(10000));
    }

    public function testItKeepsUnrecognisedFieldsOnTheServer(): void
    {
        $this->client->pushJson(200, ['server' => $this->serverRow(['something_new' => 'value'])]);

        $server = $this->binarylane()->servers()->get(1234);

        $this->assertSame('value', $server->raw['something_new']);
    }

    public function testDescribeIsOneLine(): void
    {
        $server = Server::fromArray($this->serverRow());

        $this->assertSame('#1234 vps01.example.test (std-2vcpu, active)', $server->describe());
    }
}
