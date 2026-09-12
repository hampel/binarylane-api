<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Entity\AdvancedFirewallRule;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Enum\AdvancedFeature;
use Hampel\BinaryLane\Api\Enum\AdvancedFirewallRuleAction;
use Hampel\BinaryLane\Api\Enum\AdvancedFirewallRuleProtocol;
use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Enum\ThresholdAlertType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\AdvancedFeatures;
use Hampel\BinaryLane\Api\Request\ChangeImage;
use Hampel\BinaryLane\Api\Request\Resize;
use Hampel\BinaryLane\Api\Request\SizeOptions;
use Hampel\BinaryLane\Api\Request\TakeBackup;
use Hampel\BinaryLane\Api\Request\ThresholdAlert;

final class ServerActionsTest extends TestCase
{
    public function testEveryActionIsTheSamePostWithATypeDiscriminator(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $action = $this->binarylane()->serverActions()->powerOn(1234);

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('/v2/servers/1234/actions', $this->sentPath());
        $this->assertSame(['type' => 'power_on'], $this->sentBody());
        $this->assertSame(1, $action?->id);
    }

    /**
     * Every action declares a bodiless 202 alongside its 200. There is no action to await
     * when that happens, and pretending otherwise would produce an action with an id of 0.
     */
    public function testAnAcceptedActionWithNoBodyAnswersNull(): void
    {
        $this->client->pushRaw(202, '');

        $this->assertNull($this->binarylane()->serverActions()->powerOn(1234));
    }

    public function testThePowerActionsSendTheRightTypes(): void
    {
        $actions = $this->binarylane()->serverActions();

        foreach ([
            'power_on' => static fn () => $actions->powerOn(1234),
            'power_off' => static fn () => $actions->powerOff(1234),
            'power_cycle' => static fn () => $actions->powerCycle(1234),
            'reboot' => static fn () => $actions->reboot(1234),
            'shutdown' => static fn () => $actions->shutdown(1234),
            'uncancel' => static fn () => $actions->uncancel(1234),
            'is_running' => static fn () => $actions->isRunning(1234),
            'ping' => static fn () => $actions->ping(1234),
            'uptime' => static fn () => $actions->uptime(1234),
            'detach_backup' => static fn () => $actions->detachBackup(1234),
            'disable_backups' => static fn () => $actions->disableBackups(1234),
            'enable_backups' => static fn () => $actions->enableBackups(1234),
            'enable_ipv6' => static fn () => $actions->enableIpv6(1234),
            'disable_selinux' => static fn () => $actions->disableSelinux(1234),
        ] as $type => $call) {
            $this->client->pushJson(200, $this->action(1));

            $call();

            $this->assertSame(['type' => $type], $this->sentBody(), "for {$type}");
        }
    }

    /**
     * The answer to `uptime` is not in the response - it arrives in the completed action's
     * resultData.
     */
    public function testAQuestionActionCarriesItsAnswerInResultData(): void
    {
        $this->client->pushJson(200, $this->action(1, 'completed', [
            'type' => 'uptime',
            'result_data' => '14 days, 3 hours',
        ]));

        $action = $this->binarylane()->serverActions()->uptime(1234);

        $this->assertSame('14 days, 3 hours', $action?->resultData);
    }

    public function testRenameSendsTheName(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->rename(1234, '  vps02.example.test  ');

        $this->assertSame(['type' => 'rename', 'name' => 'vps02.example.test'], $this->sentBody());
    }

    public function testRenameRefusesAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->rename(1234, '   ');
    }

    public function testRebuildCanBeGivenAnImageOrLeftToTheExistingOne(): void
    {
        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->rebuild(1234);
        $this->assertSame(['type' => 'rebuild'], $this->sentBody());

        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->rebuild(1234, 'ubuntu-24-04-lts');
        $this->assertSame(['type' => 'rebuild', 'image' => 'ubuntu-24-04-lts'], $this->sentBody());
    }

    public function testRestoreTakesABackupImageId(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->restore(1234, 9001);

        $this->assertSame(['type' => 'restore', 'image' => 9001], $this->sentBody());
    }

    /**
     * The action is performed on the SOURCE and destroys the TARGET, so the one guard worth
     * having is that they are not the same server.
     */
    public function testCloneUsingBackupRefusesTheSourceAsItsOwnTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be the source server itself');

        $this->binarylane()->serverActions()->cloneUsingBackup(1234, 9001, 1234);
    }

    public function testCloneUsingBackupIsAddressedToTheSourceServer(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->cloneUsingBackup(1234, 9001, 5678, 'restored.example.test');

        $this->assertSame('/v2/servers/1234/actions', $this->sentPath(), 'addressed to the source');
        $this->assertSame([
            'type' => 'clone_using_backup',
            'image_id' => 9001,
            'target_server_id' => 5678,
            'name' => 'restored.example.test',
        ], $this->sentBody());
    }

    public function testTakeBackupNamesItsStrategy(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->takeBackup(
            1234,
            TakeBackup::intoFreeSlot(BackupSlot::Temporary)->withLabel('before the upgrade')
        );

        $this->assertSame([
            'type' => 'take_backup',
            'replacement_strategy' => 'none',
            'backup_type' => 'temporary',
            'label' => 'before the upgrade',
        ], $this->sentBody());
    }

    /**
     * The strategy that names a backup sends no backup type - the slot is whatever that
     * backup is in.
     */
    public function testReplacingASpecificBackupSendsNoBackupType(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->takeBackup(1234, TakeBackup::replacing(9001));

        $this->assertSame([
            'type' => 'take_backup',
            'replacement_strategy' => 'specified',
            'backup_id_to_replace' => 9001,
        ], $this->sentBody());
    }

    public function testOnlyTheFreeSlotStrategyDestroysNothing(): void
    {
        $this->assertFalse(TakeBackup::intoFreeSlot(BackupSlot::Daily)->canReplace());
        $this->assertTrue(TakeBackup::replacingOldest(BackupSlot::Daily)->canReplace());
        $this->assertTrue(TakeBackup::replacingNewest(BackupSlot::Daily)->canReplace());
        $this->assertTrue(TakeBackup::replacing(9001)->canReplace());
    }

    public function testResizeSendsWhatItWasBuiltFrom(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->resize(
            1234,
            Resize::toSize('std-4vcpu')->withOptions(SizeOptions::none()->withMemory(8192))
        );

        $this->assertSame([
            'type' => 'resize',
            'size' => 'std-4vcpu',
            'options' => ['memory' => 8192],
        ], $this->sentBody());
    }

    public function testAResizeCarryingAnImageChangeIsDestructive(): void
    {
        $resize = Resize::toSize('std-4vcpu')->withImage(ChangeImage::to('ubuntu-24-04-lts'));

        $this->assertTrue($resize->isDestructive());
        $this->assertContains(
            'the server will be rebuilt on a new image and everything on its disks discarded',
            $resize->hazards()
        );
    }

    /**
     * Reducing a retained backup count deletes those backups, which is the hazard that does
     * not look like one.
     */
    public function testAResizeThatReducesBackupRetentionIsDestructive(): void
    {
        $server = Server::fromArray([
            'id' => 1234,
            'selected_size_options' => [
                'daily_backups' => 7,
                'weekly_backups' => 4,
                'monthly_backups' => 2,
                'offsite_backups' => false,
                'ipv4_addresses' => 1,
                'memory' => 4096,
                'disk' => 80,
                'transfer' => 1.0,
            ],
        ]);

        $resize = Resize::options(SizeOptions::none()->withWeeklyBackups(0));

        $this->assertFalse($resize->isDestructive(), 'without the server there is nothing to compare');
        $this->assertTrue($resize->isDestructive($server));
        $this->assertSame(
            ['4 of the 4 retained weekly backups will be deleted'],
            $resize->hazards($server)
        );
    }

    public function testAResizeThatChangesNothingIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Resize::options(SizeOptions::none());
    }

    public function testAPreActionBackupIsSentAlongside(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->resize(
            1234,
            Resize::toSize('std-4vcpu')->withPreActionBackup(TakeBackup::intoFreeSlot(BackupSlot::Temporary))
        );

        $body = $this->sentBody();

        $this->assertSame(['replacement_strategy' => 'none', 'backup_type' => 'temporary'], $body['pre_action_backup']);
    }

    public function testTheDiskActionsCarryTheirIds(): void
    {
        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->addDisk(1234, 50, 'Data');
        $this->assertSame(['type' => 'add_disk', 'size_gigabytes' => 50, 'description' => 'Data'], $this->sentBody());

        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->resizeDisk(1234, 2, 100);
        $this->assertSame(['type' => 'resize_disk', 'disk_id' => 2, 'size_gigabytes' => 100], $this->sentBody());

        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->deleteDisk(1234, 2);
        $this->assertSame(['type' => 'delete_disk', 'disk_id' => 2], $this->sentBody());
    }

    /**
     * Null gets a default description added; an empty string prevents one. Both have to reach
     * the API as written.
     */
    public function testAnEmptyDiskDescriptionIsSentAndAnOmittedOneIsNot(): void
    {
        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->addDisk(1234, 50, '');
        $this->assertSame('', $this->sentBody()['description']);

        $this->client->pushJson(200, $this->action(1));
        $this->binarylane()->serverActions()->addDisk(1234, 50);
        $this->assertArrayNotHasKey('description', $this->sentBody());
    }

    public function testChangeNetworkWithNoVpcMovesToThePublicNetwork(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->changeNetwork(1234);

        $this->assertSame(['type' => 'change_network', 'vpc_id' => null], $this->sentBody());
    }

    public function testChangeVpcIpv4NeedsBothAddresses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->changeVpcIpv4(1234, '10.0.0.1', '');
    }

    public function testChangeReverseNameCanClearTheName(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->changeReverseName(1234, '203.0.113.10');

        $this->assertSame([
            'type' => 'change_reverse_name',
            'ipv4_address' => '203.0.113.10',
            'reverse_name' => null,
        ], $this->sentBody());
    }

    public function testChangePartnerRefusesTheServerItself(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->changePartner(1234, 1234);
    }

    public function testTheFirewallRuleSetIsSentWhole(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->changeAdvancedFirewallRules(1234, [
            new AdvancedFirewallRule(
                ['0.0.0.0/0'],
                ['203.0.113.10'],
                AdvancedFirewallRuleProtocol::Tcp,
                AdvancedFirewallRuleAction::Accept,
                ['443'],
            ),
        ]);

        $body = $this->sentBody();

        $this->assertSame('change_advanced_firewall_rules', $body['type']);
        $this->assertIsArray($body['firewall_rules']);
        $this->assertCount(1, $body['firewall_rules']);
        $this->assertIsArray($body['firewall_rules'][0]);
        $this->assertSame('tcp', $body['firewall_rules'][0]['protocol']);
    }

    public function testAnEmptyFeatureListDisablesEverythingAndIsSent(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->changeAdvancedFeatures(
            1234,
            AdvancedFeatures::none()->withoutFeatures()
        );

        $this->assertSame([
            'type' => 'change_advanced_features',
            'enabled_advanced_features' => [],
        ], $this->sentBody());
    }

    /**
     * A read-only feature cannot be enabled, and including one usually means a fetched set was
     * echoed back without filtering.
     */
    public function testAReadOnlyFeatureIsRefusedRatherThanSent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be enabled');

        AdvancedFeatures::none()->withFeatures([AdvancedFeature::CloudInit]);
    }

    public function testAutomaticAndExplicitSelectionCannotBothBeSent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot both be sent');

        AdvancedFeatures::none()->withProcessorModel(3)->withAutomaticProcessorModel();
    }

    public function testAChangeWithNothingSetIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->changeAdvancedFeatures(1234, AdvancedFeatures::none());
    }

    public function testThresholdAlertsMergeRatherThanReplace(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->changeThresholdAlerts(1234, [
            ThresholdAlert::at(ThresholdAlertType::Cpu, 90),
            ThresholdAlert::disable(ThresholdAlertType::NetworkIncoming),
        ]);

        $this->assertSame([
            'type' => 'change_threshold_alerts',
            'threshold_alerts' => [
                ['alert_type' => 'cpu', 'enabled' => true, 'value' => 90],
                ['alert_type' => 'network-incoming', 'enabled' => false],
            ],
        ], $this->sentBody());
    }

    public function testChangeBackupScheduleValidatesItsRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->changeBackupSchedule(1234, dayOfWeek: 7);
    }

    public function testChangeBackupScheduleRefusesAnEmptyChange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->serverActions()->changeBackupSchedule(1234);
    }

    /**
     * The documented extension point: an action added to the API after this release.
     */
    public function testPerformReachesAnActionThisPackageDoesNotName(): void
    {
        $this->client->pushJson(200, $this->action(1));

        $this->binarylane()->serverActions()->perform(1234, 'some_new_action', ['field' => 'value']);

        $this->assertSame(['type' => 'some_new_action', 'field' => 'value'], $this->sentBody());
    }


    /**
     * The forty-two discriminator values in the specification, each mapped to the method that
     * sends it.
     *
     * This exists as a completeness check rather than a behaviour one: the API chooses between
     * these operations by a string, so an action this package forgot to wrap is invisible
     * except by counting. The list is the specification's `discriminator.mapping` keys, in its
     * order.
     */
    public function testEveryDocumentedServerActionHasAMethod(): void
    {
        $actions = $this->binarylane()->serverActions();
        $rule = new AdvancedFirewallRule(
            ['0.0.0.0/0'],
            ['203.0.113.10'],
            AdvancedFirewallRuleProtocol::Tcp,
            AdvancedFirewallRuleAction::Accept,
        );

        $calls = [
            'add_disk' => static fn () => $actions->addDisk(1, 10),
            'attach_backup' => static fn () => $actions->attachBackup(1, 9001),
            'change_advanced_features' => static fn () => $actions->changeAdvancedFeatures(1, AdvancedFeatures::none()->withoutFeatures()),
            'change_advanced_firewall_rules' => static fn () => $actions->changeAdvancedFirewallRules(1, [$rule]),
            'change_backup_schedule' => static fn () => $actions->changeBackupSchedule(1, hourOfDay: 2),
            'change_ipv6' => static fn () => $actions->changeIpv6(1, true),
            'change_ipv6_reverse_nameservers' => static fn () => $actions->changeIpv6ReverseNameservers(1, ['ns1.example.test']),
            'change_kernel' => static fn () => $actions->changeKernel(1, 7),
            'change_manage_offsite_backup_copies' => static fn () => $actions->changeManageOffsiteBackupCopies(1, true),
            'change_network' => static fn () => $actions->changeNetwork(1, 3),
            'change_offsite_backup_location' => static fn () => $actions->changeOffsiteBackupLocation(1, 's3://bucket'),
            'change_partner' => static fn () => $actions->changePartner(1, 2),
            'change_port_blocking' => static fn () => $actions->changePortBlocking(1, false),
            'change_region' => static fn () => $actions->changeRegion(1, 'mel'),
            'change_reverse_name' => static fn () => $actions->changeReverseName(1, '203.0.113.10', 'host.example.test'),
            'change_separate_private_network_interface' => static fn () => $actions->changeSeparatePrivateNetworkInterface(1, true),
            'change_source_and_destination_check' => static fn () => $actions->changeSourceAndDestinationCheck(1, false),
            'change_threshold_alerts' => static fn () => $actions->changeThresholdAlerts(1, [ThresholdAlert::enable(ThresholdAlertType::Cpu)]),
            'change_vpc_ipv4' => static fn () => $actions->changeVpcIpv4(1, '10.0.0.1', '10.0.0.2'),
            'clone_using_backup' => static fn () => $actions->cloneUsingBackup(1, 9001, 2),
            'delete_disk' => static fn () => $actions->deleteDisk(1, 2),
            'detach_backup' => static fn () => $actions->detachBackup(1),
            'disable_backups' => static fn () => $actions->disableBackups(1),
            'disable_selinux' => static fn () => $actions->disableSelinux(1),
            'enable_backups' => static fn () => $actions->enableBackups(1),
            'enable_ipv6' => static fn () => $actions->enableIpv6(1),
            'is_running' => static fn () => $actions->isRunning(1),
            'password_reset' => static fn () => $actions->passwordReset(1),
            'ping' => static fn () => $actions->ping(1),
            'power_cycle' => static fn () => $actions->powerCycle(1),
            'power_off' => static fn () => $actions->powerOff(1),
            'power_on' => static fn () => $actions->powerOn(1),
            'reboot' => static fn () => $actions->reboot(1),
            'rebuild' => static fn () => $actions->rebuild(1, 'ubuntu-24-04-lts'),
            'rename' => static fn () => $actions->rename(1, 'vps01.example.test'),
            'resize' => static fn () => $actions->resize(1, Resize::toSize('std-4vcpu')),
            'resize_disk' => static fn () => $actions->resizeDisk(1, 2, 40),
            'restore' => static fn () => $actions->restore(1, 9001),
            'shutdown' => static fn () => $actions->shutdown(1),
            'take_backup' => static fn () => $actions->takeBackup(1, TakeBackup::intoFreeSlot(BackupSlot::Daily)),
            'uncancel' => static fn () => $actions->uncancel(1),
            'uptime' => static fn () => $actions->uptime(1),
        ];

        $this->assertCount(42, $calls, 'the specification declares 42 server action types');

        foreach ($calls as $type => $call) {
            $this->client->pushJson(200, $this->action(1));

            $call();

            $body = $this->sentBody();

            $this->assertSame($type, $body['type'] ?? null, "the method for {$type} sends the wrong type");
        }
    }

    public function testBackupBeforeChangesTakesTheBackupAndWaitsForIt(): void
    {
        $this->client
            ->pushJson(200, $this->action(77, 'in-progress', ['type' => 'take_backup']))
            ->pushJson(200, $this->action(77, 'completed', ['type' => 'take_backup']));

        $action = $this->binarylane()->serverActions()->backupBeforeChanges(1234, 'before the upgrade');

        $this->assertTrue($action->isSuccessful());
        $this->assertSame(77, $action->id);
        $this->assertCount(2, $this->client->requests);
        $this->assertSame('/v2/actions/77', $this->sentPath());
    }
}
