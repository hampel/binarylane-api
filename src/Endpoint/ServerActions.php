<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Entity\AdvancedFirewallRule;
use Hampel\BinaryLane\Api\Enum\BackupSlot;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\AdvancedFeatures;
use Hampel\BinaryLane\Api\Request\ImageOptions;
use Hampel\BinaryLane\Api\Request\Resize as ResizeRequest;
use Hampel\BinaryLane\Api\Request\TakeBackup;
use Hampel\BinaryLane\Api\Request\ThresholdAlert;

/**
 * Everything you can do TO a server.
 *
 * `POST /v2/servers/{id}/actions`
 *
 * Forty-three operations that are all the same request: one path, one POST, and a `type`
 * discriminator choosing between them. This class is that discriminator made into methods,
 * so the fields each action accepts are its arguments rather than a shape to get right.
 *
 * EVERY ONE OF THESE ANSWERS WITH AN Action, NOT A RESULT. The work has not happened when the
 * call returns. `$binarylane->actions()->await($action)` is the wait, and it is the only
 * thing that reports whether the work succeeded - see Endpoint\Actions.
 *
 * AND IT MAY ANSWER 202 WITH NOTHING AT ALL. The specification declares a bodiless 202 on
 * every one of these alongside the 200 that carries the action. When that happens there is no
 * action to await: the methods return `?Action`, null means "accepted, nothing to follow",
 * and `Servers::actions($id)` is where to look for what it started.
 *
 * FOUR OF THESE DESTROY DATA WITH NO CONFIRMATION, in BinaryLane's own words - rebuild(),
 * restore(), cloneUsingBackup() (on the TARGET, not the server the call is addressed to) and
 * resize() when it carries an image change or reduces a backup count. Each is marked below.
 *
 * A SERVER UNDER MAINTENANCE REFUSES MOST OF THESE, and so does one still building. Both come
 * back as a 400 about the request rather than about the server; `Server::isActionable()` is
 * the check that costs nothing.
 */
final class ServerActions extends Endpoint
{
    // ----------------------------------------------------------------------------------
    // Power
    // ----------------------------------------------------------------------------------

    /**
     * Power a server on. For a server that was powered off, from inside or by an action.
     *
     * A CANCELLED SERVER WILL NOT POWER ON - it is in `archive` and needs uncancel() first.
     */
    public function powerOn(int $serverId): ?Action
    {
        return $this->perform($serverId, 'power_on');
    }

    /**
     * Power a server off.
     *
     * THE HARD WAY - equivalent to pulling the plug, not to asking the operating system.
     * shutdown() is the clean one. This is what to use when a shutdown has not worked.
     */
    public function powerOff(int $serverId): ?Action
    {
        return $this->perform($serverId, 'power_off');
    }

    /**
     * Power off and on again. Hard, like powerOff().
     */
    public function powerCycle(int $serverId): ?Action
    {
        return $this->perform($serverId, 'power_cycle');
    }

    /**
     * Ask the operating system to reboot - the clean restart.
     */
    public function reboot(int $serverId): ?Action
    {
        return $this->perform($serverId, 'reboot');
    }

    /**
     * Ask the operating system to shut down cleanly.
     *
     * CAN STOP AND ASK A QUESTION. When the clean shutdown does not complete, the action
     * raises an `allow-unclean-power-off` interaction and waits indefinitely for an answer -
     * await() reports that as an ActionBlockedException, and Actions::proceed() is the answer.
     * Saying yes is a hard power cut on a server that did not shut down.
     */
    public function shutdown(int $serverId): ?Action
    {
        return $this->perform($serverId, 'shutdown');
    }

    // ----------------------------------------------------------------------------------
    // Questions - actions whose answer is whether they SUCCEED
    // ----------------------------------------------------------------------------------
    //
    // THE ANSWER IS THE STATUS, NOT THE PAYLOAD, and that is worth reading twice because it
    // is the opposite of what the shape suggests. Measured on 13 September 2026, both ways,
    // against a server that was stopped and then started:
    //
    //                     server running                     server stopped
    //   is_running        completed, result_data NULL        errored
    //   uptime            completed, result_data "0 days,  0:02"   errored
    //
    // So `is_running` never reports anything at all - it answers by completing. And an
    // errored action is how this API says "no", which collides with Actions::await(), whose
    // whole job is to raise on one. Asking "is this server up?" through await() therefore
    // throws when the answer is simply no.
    //
    // ask() is the resolution: it performs, waits, and maps an errored action to null instead
    // of raising. The methods below it return ?Action like every other action on this class,
    // for a caller that wants the raw record.

    /**
     * Is this server running?
     *
     * Returns the action, not the answer - see checkRunning(), which is almost certainly what
     * you want. A COMPLETED action means yes and an ERRORED one means no, so awaiting this
     * with Actions::await() raises for a stopped server.
     */
    public function isRunning(int $serverId): ?Action
    {
        return $this->perform($serverId, 'is_running');
    }

    /**
     * Try to ping the server.
     *
     * NOT MEASURED. `is_running` and `uptime` both answer by completing or erroring, and this
     * is the same shape, but nothing here has watched it do so - use ask() and read what comes
     * back rather than trusting the pattern.
     */
    public function ping(int $serverId): ?Action
    {
        return $this->perform($serverId, 'ping');
    }

    /**
     * How long has this server been up?
     *
     * Returns the action, not the answer - see checkUptime(). The uptime lands in
     * `result_data` as a preformatted string: `"0 days,  0:02"`, doubled space and all. It is
     * for showing a person, not for arithmetic.
     */
    public function uptime(int $serverId): ?Action
    {
        return $this->perform($serverId, 'uptime');
    }

    /**
     * Perform a question-shaped action and wait for its answer, where ERRORED means "no"
     * rather than "something went wrong".
     *
     * Answers the completed action, or NULL when it errored. Everything else still raises:
     * ActionBlockedException for an action waiting on a question or an invoice, and
     * ActionTimedOutException for a deadline, because neither of those is an answer.
     *
     * THE ONE THING THIS CANNOT DO is tell "the server said no" from "the check itself
     * failed". BinaryLane reports an errored action with `error_message` null and a `reason`
     * that narrates what was attempted, so there is nothing in the response to separate them.
     * A null here means "the action did not succeed", and for `is_running` against a reachable
     * account that means the server is not running.
     *
     * @param  callable(Action): void|null  $onPoll
     * @param  callable(int): void|null  $wait
     */
    public function ask(
        int $serverId,
        string $type,
        ?int $timeout = null,
        ?int $interval = null,
        ?callable $onPoll = null,
        ?callable $wait = null,
    ): ?Action {
        $action = $this->perform($serverId, $type);

        if ($action === null) {
            throw new InvalidArgumentException(sprintf(
                'The API accepted the "%s" action without returning one, so there is nothing to '
                    . 'wait on and no answer to read. Check Servers::actions() for what it started.',
                $type
            ));
        }

        try {
            return (new Actions($this->connection, $this->logger))
                ->await($action, $timeout, $interval, $onPoll, $wait);
        } catch (ActionFailedException) {
            return null;
        }
    }

    /**
     * Is this server powered on?
     *
     * THE QUESTION `Server::$status` CANNOT ANSWER. A server that is powered off reports
     * `active` like every other - measured - so this action is the only route to the truth,
     * and it costs a poll or two rather than a field read.
     *
     * True when the action completed, false when it errored. Raises only for a blocked action
     * or a timeout.
     *
     * @param  callable(int): void|null  $wait
     */
    public function checkRunning(
        int $serverId,
        ?int $timeout = null,
        ?int $interval = null,
        ?callable $wait = null,
    ): bool {
        return $this->ask($serverId, 'is_running', $timeout, $interval, null, $wait) !== null;
    }

    /**
     * How long the server has been up, or null when it is not running.
     *
     * A PREFORMATTED STRING - `"0 days,  0:02"`, with two spaces before the clock. BinaryLane
     * formats it for display and there is no numeric form, so parsing it is on you and the
     * format is not promised.
     *
     * Null covers both "not running" and "the check failed", which the API does not
     * distinguish - see ask().
     *
     * @param  callable(int): void|null  $wait
     */
    public function checkUptime(
        int $serverId,
        ?int $timeout = null,
        ?int $interval = null,
        ?callable $wait = null,
    ): ?string {
        $action = $this->ask($serverId, 'uptime', $timeout, $interval, null, $wait);

        return $action !== null && $action->hasResult() ? $action->resultData : null;
    }

    // ----------------------------------------------------------------------------------
    // Lifecycle
    // ----------------------------------------------------------------------------------

    /**
     * Change the server's hostname.
     *
     * THE HOSTNAME IS NOT THE IDENTIFIER and this is why - it changes. `permalink` does not.
     */
    public function rename(int $serverId, string $name): ?Action
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A rename needs a hostname.');
        }

        return $this->perform($serverId, 'rename', ['name' => $name]);
    }

    /**
     * Revert a cancellation, bringing an `archive` server back.
     */
    public function uncancel(int $serverId): ?Action
    {
        return $this->perform($serverId, 'uncancel');
    }

    /**
     * Change the plan, the options, the image or the licences.
     *
     * MAY BE DESTRUCTIVE AND WILL NOT ASK. An image change rebuilds the server; reducing a
     * retained backup count deletes those backups. Request\Resize::hazards() spells out what
     * a particular request would do, and withPreActionBackup() is the only safety net on offer.
     */
    public function resize(int $serverId, ResizeRequest $request): ?Action
    {
        return $this->perform($serverId, 'resize', $request->toArray());
    }

    /**
     * Rebuild the server on an image.
     *
     * DESTRUCTIVE, WITH NO CONFIRMATION. The specification's own note. Everything on the
     * disks is discarded and replaced with a fresh install.
     *
     * @param  int|string|null  $image  an operating system id or slug, or a BACKUP image id.
     *                                  Null rebuilds on the server's existing image
     */
    public function rebuild(int $serverId, int|string|null $image = null, ?ImageOptions $options = null): ?Action
    {
        $payload = [];

        if ($image !== null) {
            $payload['image'] = is_string($image) ? trim($image) : $image;
        }

        if ($options !== null && !$options->isEmpty()) {
            $payload['options'] = $options->toArray();
        }

        return $this->perform($serverId, 'rebuild', $payload);
    }

    /**
     * Restore a backup over the server's existing disks.
     *
     * DESTRUCTIVE, WITH NO CONFIRMATION. The existing disks are removed. The backup must fit -
     * see Entity\BackupInfo::fitsIn(), which compares against the target's disk size - and an
     * ISO backup cannot be restored at all.
     *
     * @param  int  $backupId  the image id of the backup. Snapshots are not supported
     */
    public function restore(int $serverId, int $backupId): ?Action
    {
        if ($backupId < 1) {
            throw new InvalidArgumentException('A restore needs the image id of the backup to restore.');
        }

        return $this->perform($serverId, 'restore', ['image' => $backupId]);
    }

    /**
     * Move the server to another region.
     *
     * Regions have requirements a server must meet before it can move; BinaryLane's knowledge
     * base is the reference, and a server that does not meet them is refused with a 400.
     */
    public function changeRegion(int $serverId, string $region): ?Action
    {
        $region = trim($region);

        if ($region === '') {
            throw new InvalidArgumentException('A region change needs a region slug.');
        }

        return $this->perform($serverId, 'change_region', ['region' => $region]);
    }

    /**
     * Reset the remote user's password, or clear the administrator password on Windows.
     *
     * TWO SEPARATE THINGS DECIDE WHETHER THIS WORKS AND WHAT IT COSTS. Whether it is possible
     * at all is `Server::$passwordChangeSupported`; whether it REBOOTS THE SERVER is the
     * image's `DistributionInfo::$passwordRecovery`. A server whose recovery type is
     * `offline-change` restarts to do this.
     *
     * With no password given, one is generated and emailed to the account address - nothing
     * is returned.
     */
    public function passwordReset(
        int $serverId,
        ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
    ): ?Action {
        $payload = [];

        if ($username !== null) {
            $payload['username'] = $username;
        }

        if ($password !== null) {
            $payload['password'] = $password;
        }

        return $this->perform($serverId, 'password_reset', $payload);
    }

    /**
     * Disable SELinux on the server.
     */
    public function disableSelinux(int $serverId): ?Action
    {
        return $this->perform($serverId, 'disable_selinux');
    }

    // ----------------------------------------------------------------------------------
    // Backups
    // ----------------------------------------------------------------------------------

    /**
     * Turn on two daily backups.
     *
     * A SHORTCUT WITH A FIXED ANSWER - two daily, and nothing else. For any other retention,
     * resize() with SizeOptions::withDailyBackups() and friends is the way, since retention is
     * a size option rather than a backup setting.
     */
    public function enableBackups(int $serverId): ?Action
    {
        return $this->perform($serverId, 'enable_backups');
    }

    /**
     * Turn scheduled backups off.
     *
     * WHAT HAPPENS TO THE EXISTING BACKUPS IS NOT STATED by the specification. Treat them as
     * at risk rather than retained, and read the account's own backup policy before relying
     * on one taken before this call.
     */
    public function disableBackups(int $serverId): ?Action
    {
        return $this->perform($serverId, 'disable_backups');
    }

    /**
     * Take a backup now.
     *
     * Three of the four ways to build the request destroy an existing backup when the slot is
     * full; `TakeBackup::intoFreeSlot()` is the one that fails instead.
     */
    public function takeBackup(int $serverId, TakeBackup $request): ?Action
    {
        return $this->perform($serverId, 'take_backup', $request->toArray());
    }

    /**
     * Move when the scheduled backups run.
     *
     * Each field left null keeps its current value. The hour is approximate - it schedules a
     * window, not a moment; see Entity\BackupWindow.
     *
     * @param  int|null  $dayOfWeek  0 is Sunday
     */
    public function changeBackupSchedule(
        int $serverId,
        ?int $hourOfDay = null,
        ?int $dayOfWeek = null,
        ?int $dayOfMonth = null,
    ): ?Action {
        if ($hourOfDay !== null && ($hourOfDay < 0 || $hourOfDay > 23)) {
            throw new InvalidArgumentException('The backup hour of day is 0 to 23.');
        }

        if ($dayOfWeek !== null && ($dayOfWeek < 0 || $dayOfWeek > 6)) {
            throw new InvalidArgumentException('The backup day of week is 0 (Sunday) to 6 (Saturday).');
        }

        if ($dayOfMonth !== null && ($dayOfMonth < 1 || $dayOfMonth > 31)) {
            throw new InvalidArgumentException('The backup day of month is 1 to 31.');
        }

        $payload = array_filter([
            'backup_hour_of_day' => $hourOfDay,
            'backup_day_of_week' => $dayOfWeek,
            'backup_day_of_month' => $dayOfMonth,
        ], static fn (?int $value): bool => $value !== null);

        if ($payload === []) {
            throw new InvalidArgumentException(
                'A backup schedule change with nothing to change would do nothing; give it an hour, '
                    . 'a day of week or a day of month.'
            );
        }

        return $this->perform($serverId, 'change_backup_schedule', $payload);
    }

    /**
     * Mount a backup image on the server so its contents can be read from inside.
     *
     * NON-DESTRUCTIVE, unlike restore() - the backup is attached alongside the existing disks
     * rather than replacing them. It DETACHES ITSELF at `attachmentExpires`; see
     * Entity\AttachedBackup.
     */
    public function attachBackup(int $serverId, int $backupId): ?Action
    {
        if ($backupId < 1) {
            throw new InvalidArgumentException('Attaching a backup needs its image id.');
        }

        return $this->perform($serverId, 'attach_backup', ['image' => $backupId]);
    }

    /**
     * Unmount whatever backup is attached.
     */
    public function detachBackup(int $serverId): ?Action
    {
        return $this->perform($serverId, 'detach_backup');
    }

    /**
     * Restore one server's backup onto a DIFFERENT server.
     *
     * READ THE ARGUMENT ORDER TWICE. The action is performed on the SOURCE server - the one
     * the backup belongs to - and it is DESTRUCTIVE ON THE TARGET, which is overwritten with
     * no confirmation. Getting these the wrong way round destroys the wrong machine.
     *
     * The target must have finished building: the specification says this fails unless the
     * target server is available.
     *
     * @param  int  $serverId  the SOURCE - whose backup is being used
     * @param  int  $backupId  the image id of the backup
     * @param  int  $targetServerId  the server being OVERWRITTEN
     * @param  string|null  $name  a hostname for the target after the clone
     */
    public function cloneUsingBackup(
        int $serverId,
        int $backupId,
        int $targetServerId,
        ?string $name = null,
    ): ?Action {
        if ($backupId < 1) {
            throw new InvalidArgumentException('Cloning from a backup needs the backup image id.');
        }

        if ($targetServerId < 1) {
            throw new InvalidArgumentException('Cloning from a backup needs the id of the target server.');
        }

        if ($targetServerId === $serverId) {
            throw new InvalidArgumentException(
                'The target of a clone cannot be the source server itself. To restore a backup onto '
                    . 'the server it came from, use restore().'
            );
        }

        $payload = ['image_id' => $backupId, 'target_server_id' => $targetServerId];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        return $this->perform($serverId, 'clone_using_backup', $payload);
    }

    /**
     * Set or clear a custom offsite backup location.
     *
     * Null clears it, which returns the server to BinaryLane's own offsite storage.
     */
    public function changeOffsiteBackupLocation(int $serverId, ?string $location = null): ?Action
    {
        return $this->perform($serverId, 'change_offsite_backup_location', [
            'offsite_backup_location' => $location,
        ]);
    }

    /**
     * Decide whether BinaryLane prunes old copies at a custom offsite location.
     *
     * ONLY MEANS ANYTHING WITH A CUSTOM LOCATION. Set to false against a custom location, the
     * copies accumulate without limit - see Entity\OffsiteBackupSettings.
     */
    public function changeManageOffsiteBackupCopies(int $serverId, bool $manage): ?Action
    {
        return $this->perform($serverId, 'change_manage_offsite_backup_copies', [
            'manage_offsite_backup_copies' => $manage,
        ]);
    }

    // ----------------------------------------------------------------------------------
    // Disks
    // ----------------------------------------------------------------------------------

    /**
     * Add a disk to the server.
     *
     * NEEDS UNALLOCATED SPACE ALREADY ON THE SERVER - this carves a disk out of storage the
     * server has and does not buy more. Growing the total is a resize, with
     * SizeOptions::withDisk().
     *
     * `$description` has the null-versus-empty-string trap: null gets a default description
     * added, and an EMPTY STRING prevents one.
     */
    public function addDisk(int $serverId, int $sizeGigabytes, ?string $description = null): ?Action
    {
        if ($sizeGigabytes < 1) {
            throw new InvalidArgumentException('A new disk needs a positive size in GB.');
        }

        $payload = ['size_gigabytes' => $sizeGigabytes];

        if ($description !== null) {
            $payload['description'] = $description;
        }

        return $this->perform($serverId, 'add_disk', $payload);
    }

    /**
     * Resize one of the server's additional disks.
     *
     * NOT THE PRIMARY DISK - that one follows the server's size. Entity\Disk::isAdditional()
     * says which is which.
     */
    public function resizeDisk(int $serverId, int $diskId, int $sizeGigabytes): ?Action
    {
        if ($diskId < 1) {
            throw new InvalidArgumentException('Resizing a disk needs its id.');
        }

        if ($sizeGigabytes < 1) {
            throw new InvalidArgumentException('A disk size must be positive, in GB.');
        }

        return $this->perform($serverId, 'resize_disk', [
            'disk_id' => $diskId,
            'size_gigabytes' => $sizeGigabytes,
        ]);
    }

    /**
     * Delete one of the server's additional disks, and everything on it.
     */
    public function deleteDisk(int $serverId, int $diskId): ?Action
    {
        if ($diskId < 1) {
            throw new InvalidArgumentException('Deleting a disk needs its id.');
        }

        return $this->perform($serverId, 'delete_disk', ['disk_id' => $diskId]);
    }

    // ----------------------------------------------------------------------------------
    // Networking
    // ----------------------------------------------------------------------------------

    /**
     * Turn IPv6 on or off.
     */
    public function changeIpv6(int $serverId, bool $enabled): ?Action
    {
        return $this->perform($serverId, 'change_ipv6', ['enabled' => $enabled]);
    }

    /**
     * Turn IPv6 on.
     *
     * The API declares this as an action of its own as well as through change_ipv6; they do
     * the same thing, and this one takes no arguments.
     */
    public function enableIpv6(int $serverId): ?Action
    {
        return $this->perform($serverId, 'enable_ipv6');
    }

    /**
     * Set the server's IPv6 reverse nameservers.
     *
     * A REPLACEMENT: the list given is the list the server ends up with, so an empty array
     * clears them.
     *
     * @param  list<string>  $nameservers
     */
    public function changeIpv6ReverseNameservers(int $serverId, array $nameservers): ?Action
    {
        return $this->perform($serverId, 'change_ipv6_reverse_nameservers', [
            'ipv6_reverse_nameservers' => array_values($nameservers),
        ]);
    }

    /**
     * Set or clear the reverse name for one IPv4 address on the server.
     *
     * Null as the name clears the custom reverse name.
     */
    public function changeReverseName(int $serverId, string $ipv4Address, ?string $reverseName = null): ?Action
    {
        $ipv4Address = trim($ipv4Address);

        if ($ipv4Address === '') {
            throw new InvalidArgumentException('Changing a reverse name needs the IPv4 address it is for.');
        }

        return $this->perform($serverId, 'change_reverse_name', [
            'ipv4_address' => $ipv4Address,
            'reverse_name' => $reverseName,
        ]);
    }

    /**
     * Turn the default blocking of outgoing TCP 22, 25 and 3389 on or off.
     *
     * DISABLING IS FOR VERIFIED ACCOUNTS ONLY. On an unverified account this comes back as a
     * 400 about the field.
     */
    public function changePortBlocking(int $serverId, bool $enabled): ?Action
    {
        return $this->perform($serverId, 'change_port_blocking', ['enabled' => $enabled]);
    }

    /**
     * Move the server into a VPC, or back onto the region's public network.
     *
     * NULL MEANS THE PUBLIC NETWORK - it is a move, not a no-op.
     */
    public function changeNetwork(int $serverId, ?int $vpcId = null): ?Action
    {
        return $this->perform($serverId, 'change_network', ['vpc_id' => $vpcId]);
    }

    /**
     * Give the server a network interface dedicated to its VPC traffic, or take it away.
     */
    public function changeSeparatePrivateNetworkInterface(int $serverId, bool $enabled): ?Action
    {
        return $this->perform($serverId, 'change_separate_private_network_interface', ['enabled' => $enabled]);
    }

    /**
     * Turn network source and destination checking on or off, for a server in a VPC.
     *
     * TURN IT OFF FOR A NAT GATEWAY OR A ROUTER. With it on, the server can only send and
     * receive packets addressed to itself, which is exactly what a router must not do.
     */
    public function changeSourceAndDestinationCheck(int $serverId, bool $enabled): ?Action
    {
        return $this->perform($serverId, 'change_source_and_destination_check', ['enabled' => $enabled]);
    }

    /**
     * Change a server's IPv4 address within its VPC.
     *
     * BOTH ADDRESSES ARE REQUIRED - the current one as well as the new one, which is what
     * makes this safe to script: a stale idea of the current address fails rather than moving
     * the wrong thing.
     */
    public function changeVpcIpv4(int $serverId, string $currentAddress, string $newAddress): ?Action
    {
        $currentAddress = trim($currentAddress);
        $newAddress = trim($newAddress);

        if ($currentAddress === '' || $newAddress === '') {
            throw new InvalidArgumentException(
                'Changing a VPC IPv4 address needs both the current address and the new one.'
            );
        }

        return $this->perform($serverId, 'change_vpc_ipv4', [
            'current_ipv4_address' => $currentAddress,
            'new_ipv4_address' => $newAddress,
        ]);
    }

    // ----------------------------------------------------------------------------------
    // Configuration
    // ----------------------------------------------------------------------------------

    /**
     * Change the virtualisation options.
     *
     * THE FEATURE LIST REPLACES RATHER THAN MERGES - see Request\AdvancedFeatures, where an
     * empty list means "disable everything" and an absent one means "leave them alone".
     */
    public function changeAdvancedFeatures(int $serverId, AdvancedFeatures $features): ?Action
    {
        if ($features->isEmpty()) {
            throw new InvalidArgumentException(
                'An advanced features change with nothing set would do nothing. Use '
                    . 'AdvancedFeatures::withoutFeatures() to disable them all.'
            );
        }

        return $this->perform($serverId, 'change_advanced_features', $features->toArray());
    }

    /**
     * Replace the server's advanced firewall rules.
     *
     * THE WHOLE SET, IN ORDER. Rules not in the list are removed, and the order given is the
     * order they are evaluated in. An empty list removes every rule, which on a server relying
     * on them is a change worth being deliberate about.
     *
     * @param  list<AdvancedFirewallRule>  $rules
     */
    public function changeAdvancedFirewallRules(int $serverId, array $rules): ?Action
    {
        return $this->perform($serverId, 'change_advanced_firewall_rules', [
            'firewall_rules' => array_map(
                static fn (AdvancedFirewallRule $rule): array => $rule->toArray(),
                array_values($rules)
            ),
        ]);
    }

    /**
     * Boot the server on a different kernel.
     *
     * Servers::kernels() lists the ones this server may use.
     */
    public function changeKernel(int $serverId, int $kernelId): ?Action
    {
        if ($kernelId < 1) {
            throw new InvalidArgumentException('A kernel change needs the kernel id.');
        }

        return $this->perform($serverId, 'change_kernel', ['kernel' => $kernelId]);
    }

    /**
     * Set or remove this server's partner server.
     *
     * NULL REMOVES THE PARTNERSHIP. The partner must be in the SAME REGION as this server.
     */
    public function changePartner(int $serverId, ?int $partnerServerId = null): ?Action
    {
        if ($partnerServerId !== null && $partnerServerId === $serverId) {
            throw new InvalidArgumentException('A server cannot be its own partner.');
        }

        return $this->perform($serverId, 'change_partner', ['partner_server_id' => $partnerServerId]);
    }

    /**
     * Set or update threshold alerts.
     *
     * ALERTS NOT IN THE LIST ARE LEFT ALONE - unlike the firewall rules and the feature list,
     * this one merges. Each entry decides for itself whether it is changing the enabled state,
     * the value, or both; see Request\ThresholdAlert.
     *
     * @param  list<ThresholdAlert>  $alerts
     */
    public function changeThresholdAlerts(int $serverId, array $alerts): ?Action
    {
        if ($alerts === []) {
            throw new InvalidArgumentException(
                'A threshold alert change needs at least one alert; an empty list changes nothing.'
            );
        }

        return $this->perform($serverId, 'change_threshold_alerts', [
            'threshold_alerts' => array_map(
                static fn (ThresholdAlert $alert): array => $alert->toArray(),
                array_values($alerts)
            ),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // The mechanism underneath all of the above
    // ----------------------------------------------------------------------------------

    /**
     * Perform any action by its wire name.
     *
     * The extension point for an action added to the API after this release - and what every
     * method above calls. `$payload` is merged with the `type` discriminator, so it carries
     * exactly the fields that action's schema declares.
     *
     *     $binarylane->serverActions()->perform(1234, 'some_new_action', ['field' => 'value']);
     *
     * Returns null when the API answers 202 with no body, which it declares for every one of
     * these - see the class note.
     *
     * @param  array<string, mixed>  $payload
     */
    public function perform(int $serverId, string $type, array $payload = []): ?Action
    {
        $type = trim($type);

        if ($type === '') {
            throw new InvalidArgumentException('A server action needs a type.');
        }

        $response = $this->apiPost($this->path($serverId), ['type' => $type, ...$payload]);

        // requireObject() rather than object(): it returns early on a genuinely empty body,
        // so the bodiless 202 still answers null, while a 200 that parsed and lacks `action`
        // raises instead of being mistaken for one. Those two look identical through the
        // lenient accessor, which is how 0.1.0 reported a proxy's answer as "accepted".
        $action = $response->requireObject('action');

        return $action === [] ? null : Action::fromArray($action);
    }

    /**
     * Take a temporary backup and wait for it, as one call - the safety net before something
     * destructive.
     *
     * A convenience over takeBackup() plus Actions::await(), because the sequence is always
     * the same and getting it wrong means proceeding without the backup you thought you had.
     * Raises rather than returning if the backup did not complete.
     *
     * A temporary backup is kept for at most seven days.
     *
     * @param  callable(Action): void|null  $onPoll  for a progress display - see Actions::await()
     * @param  callable(int): void|null  $wait  how to wait; defaults to sleep()
     */
    public function backupBeforeChanges(
        int $serverId,
        ?string $label = null,
        BackupSlot $slot = BackupSlot::Temporary,
        ?int $timeout = null,
        ?callable $onPoll = null,
        ?callable $wait = null,
    ): Action {
        $request = TakeBackup::intoFreeSlot($slot);

        if ($label !== null) {
            $request = $request->withLabel($label);
        }

        $action = $this->takeBackup($serverId, $request);

        if ($action === null) {
            throw new InvalidArgumentException(
                'The API accepted the backup without returning an action, so there is nothing to wait '
                    . 'on. Check Servers::actions() before treating the backup as taken.'
            );
        }

        return (new Actions($this->connection, $this->logger))
            ->await($action, $timeout, onPoll: $onPoll, wait: $wait);
    }

    private function path(int $serverId): string
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException(
                sprintf('A server id must be a positive integer; %d was given.', $serverId)
            );
        }

        return 'servers/' . $serverId . '/actions';
    }
}
