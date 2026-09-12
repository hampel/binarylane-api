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
