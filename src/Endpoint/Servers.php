<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Action;
use Hampel\BinaryLane\Api\Entity\ActionLink;
use Hampel\BinaryLane\Api\Entity\AdvancedFirewallRule;
use Hampel\BinaryLane\Api\Entity\AvailableAdvancedServerFeatures;
use Hampel\BinaryLane\Api\Entity\Console;
use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Kernel;
use Hampel\BinaryLane\Api\Entity\LicensedSoftware;
use Hampel\BinaryLane\Api\Entity\Server;
use Hampel\BinaryLane\Api\Entity\ThresholdAlert;
use Hampel\BinaryLane\Api\Entity\UserData;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Request\CreateServer;
use Hampel\BinaryLane\Api\Request\UploadImage;
use Hampel\BinaryLane\Api\Result\CreatedServer;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Servers: reading them, creating them, cancelling them.
 *
 * `/v2/servers`
 *
 * EVERYTHING THAT CHANGES A RUNNING SERVER IS IN ServerActions, not here. This endpoint
 * covers the lifecycle at the ends - create, read, cancel - and the several read-only views
 * that hang off a server. Powering, resizing, rebuilding, backups and the rest are actions,
 * because that is how the API models them.
 *
 * THE TWO MUTATIONS HERE BEHAVE DIFFERENTLY FROM EACH OTHER AND FROM EVERY ACTION:
 *
 *  - create() answers with the SERVER plus links to the actions still building it. The server
 *    exists and is not ready; `status` is `new` until it is.
 *  - cancel() answers 204 with nothing at all - no action, nothing to await.
 */
final class Servers extends Endpoint
{
    public const COLLECTION = 'servers';

    /**
     * One server by id. Raises NotFoundException when there is no such server - which also
     * covers a server belonging to another account.
     */
    public function get(int $id): Server
    {
        return $this->apiObject($this->path($id), 'server', Server::fromArray(...));
    }

    /**
     * One server by id, or null when it is not visible to this token.
     */
    public function find(int $id): ?Server
    {
        return $this->apiFind($this->path($id), 'server', Server::fromArray(...));
    }

    /**
     * One page of the account's servers.
     *
     * @param  string|null  $hostname  restricts the result to the server with this hostname,
     *                                 case-insensitively. The specification is explicit that
     *                                 at most one server comes back - so it is a lookup
     *                                 wearing a filter's clothes; findByHostname() is that
     *                                 lookup with an honest signature
     * @return Page<Server>
     */
    public function list(int $page = 1, ?int $perPage = null, ?string $hostname = null): Page
    {
        return $this->apiPaginate(
            'servers',
            self::COLLECTION,
            Server::fromArray(...),
            $page,
            $perPage,
            $hostname === null ? [] : ['hostname' => $hostname]
        );
    }

    /**
     * Every server, a page at a time, as far as it is consumed.
     *
     * @return \Generator<int, Server>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('servers', self::COLLECTION, Server::fromArray(...), $perPage);
    }

    /**
     * How many servers the account has, in one request that fetches none of them.
     */
    public function count(): int
    {
        return $this->apiCount('servers', self::COLLECTION);
    }

    /**
     * The server with this hostname, or null.
     *
     * A HOSTNAME IS NOT AN IDENTIFIER. The Rename action changes it, nothing stops two servers
     * having had it at different times, and the match is case-insensitive. Key on `id` or on
     * `permalink`, and use this for finding a server a person named.
     */
    public function findByHostname(string $hostname): ?Server
    {
        $hostname = trim($hostname);

        if ($hostname === '') {
            throw new InvalidArgumentException('A hostname is needed to look a server up by one.');
        }

        return $this->list(hostname: $hostname)->first();
    }

    /**
     * Create a server.
     *
     * ANSWERS BEFORE THE SERVER IS READY. What comes back is the server as it stands - status
     * `new`, no addresses yet - and a list of links to the actions building it. Awaiting those
     * is how to wait for a usable server:
     *
     *     $created = $binarylane->servers()->create($request);
     *     $binarylane->actions()->awaitAll($created->actionIds(), timeout: 1800);
     *     $server = $binarylane->servers()->get($created->server->id);
     *
     * THE BUILD CAN STOP AND ASK A QUESTION. A server that is built but does not answer a
     * ping raises a `continue-after-ping-failure` interaction, which await() reports as an
     * ActionBlockedException rather than waiting out the timeout. See Actions::proceed().
     */
    public function create(CreateServer $request): CreatedServer
    {
        $response = $this->apiPost('servers', $request->toArray());

        return new CreatedServer(
            Server::fromArray($response->requireObject('server')),
            Cast::objects(Cast::object($response->value('links'))['actions'] ?? null, ActionLink::fromArray(...)),
        );
    }

    /**
     * Cancel a server.
     *
     * A CANCELLATION, NOT A DELETION, and the difference is recoverable: the server moves to
     * `archive`, stops, and stays that way. The Uncancel action reverses it - see
     * ServerActions::uncancel().
     *
     * ANSWERS 204 WITH NOTHING. No action comes back, so there is nothing to await and no
     * record of the cancellation in the action list. Re-reading the server is the only
     * confirmation there is.
     *
     * @param  string|null  $reason  recorded against the cancellation. At most 250 characters
     */
    public function cancel(int $id, ?string $reason = null): void
    {
        if ($reason !== null && strlen($reason) > 250) {
            throw new InvalidArgumentException(sprintf(
                'A cancellation reason may be at most 250 characters; %d were given.',
                strlen($reason)
            ));
        }

        $this->apiDelete($this->path($id), $reason === null ? [] : ['reason' => $reason]);
    }

    /**
     * One page of the actions performed on this server, newest first.
     *
     * THE PLACE TO LOOK AFTER A REQUEST THAT DIED. A mutation whose response never arrived
     * either happened or did not, and this list is what says which - which is the difference
     * between retrying a rebuild safely and doing it twice.
     *
     * @return Page<Action>
     */
    public function actions(int $id, int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate(
            $this->path($id) . '/actions',
            Actions::COLLECTION,
            Action::fromArray(...),
            $page,
            $perPage
        );
    }

    /**
     * Every action performed on this server, a page at a time.
     *
     * @return \Generator<int, Action>
     */
    public function eachAction(int $id, ?int $perPage = null): \Generator
    {
        return $this->apiEach($this->path($id) . '/actions', Actions::COLLECTION, Action::fromArray(...), $perPage);
    }

    /**
     * One action on this server.
     *
     * The same action `Actions::get()` returns, fetched through the server that owns it -
     * which additionally confirms that it does.
     */
    public function action(int $id, int $actionId): Action
    {
        return $this->apiObject($this->path($id) . '/actions/' . $actionId, 'action', Action::fromArray(...));
    }

    /**
     * The server's advanced firewall rules, in evaluation order.
     *
     * NOT PAGINATED - the response carries the whole list. Changing it means sending the
     * WHOLE list back through ServerActions::changeAdvancedFirewallRules(); see
     * Entity\AdvancedFirewallRule.
     *
     * @return list<AdvancedFirewallRule>
     */
    public function advancedFirewallRules(int $id): array
    {
        return Cast::objects(
            $this->apiGet($this->path($id) . '/advanced_firewall_rules')->requireArray('firewall_rules'),
            AdvancedFirewallRule::fromArray(...)
        );
    }

    /**
     * Which advanced features, processor models and machine types THIS server may be given.
     *
     * Worth reading before ServerActions::changeAdvancedFeatures(), because the set is per
     * server rather than per account.
     */
    public function availableAdvancedFeatures(int $id): AvailableAdvancedServerFeatures
    {
        return $this->apiObject(
            $this->path($id) . '/available_advanced_features',
            'available_advanced_server_features',
            AvailableAdvancedServerFeatures::fromArray(...)
        );
    }

    /**
     * One page of this server's backups, as images.
     *
     * @return Page<Image>
     */
    public function backups(int $id, int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate($this->path($id) . '/backups', 'backups', Image::fromArray(...), $page, $perPage);
    }

    /**
     * Every backup of this server, a page at a time.
     *
     * @return \Generator<int, Image>
     */
    public function eachBackup(int $id, ?int $perPage = null): \Generator
    {
        return $this->apiEach($this->path($id) . '/backups', 'backups', Image::fromArray(...), $perPage);
    }

    /**
     * Upload a disk image from a URL into one of this server's backup slots.
     *
     * THREE OF THE FOUR WAYS TO BUILD THE REQUEST DESTROY AN EXISTING BACKUP - see
     * Request\UploadImage, where the strategies are named for what they do.
     *
     * Answers with the action doing the upload, which can run for a long time on a large
     * image; `progress.currentStepDetail` carries the transfer rate while it does.
     */
    public function uploadBackup(int $id, UploadImage $request): Action
    {
        return Action::fromArray(
            $this->apiPost($this->path($id) . '/backups', $request->toArray())->requireObject('action')
        );
    }

    /**
     * One page of the kernels this server may boot.
     *
     * @return Page<Kernel>
     */
    public function kernels(int $id, int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate($this->path($id) . '/kernels', 'kernels', Kernel::fromArray(...), $page, $perPage);
    }

    /**
     * Every kernel this server may boot, a page at a time.
     *
     * @return \Generator<int, Kernel>
     */
    public function eachKernel(int $id, ?int $perPage = null): \Generator
    {
        return $this->apiEach($this->path($id) . '/kernels', 'kernels', Kernel::fromArray(...), $perPage);
    }

    /**
     * One page of this server's snapshots.
     *
     * LIKELY TO BE EMPTY. The specification says snapshot creation is not currently
     * supported, so this endpoint exists ahead of the feature - an empty list here is the
     * expected answer rather than a sign of anything wrong.
     *
     * @return Page<Image>
     */
    public function snapshots(int $id, int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate($this->path($id) . '/snapshots', 'snapshots', Image::fromArray(...), $page, $perPage);
    }

    /**
     * The server's threshold alerts and where each currently stands.
     *
     * NOT PAGINATED. Changing them goes through
     * ServerActions::changeThresholdAlerts().
     *
     * @return list<ThresholdAlert>
     */
    public function thresholdAlerts(int $id): array
    {
        return Cast::objects(
            $this->apiGet($this->path($id) . '/threshold_alerts')->requireArray('threshold_alerts'),
            ThresholdAlert::fromArray(...)
        );
    }

    /**
     * The ids of every server on the account with an alert currently over its threshold.
     *
     * ONE REQUEST FOR THE WHOLE FLEET, which is what makes it the right thing for a
     * monitoring loop: the per-server call above would be one request each. It answers ids
     * only - which server, not which alert - so it is a filter to then look at rather than a
     * report.
     *
     * @return list<int>
     */
    public function serversWithExceededAlerts(): array
    {
        return Cast::ints($this->apiGet('servers/threshold_alerts')->requireArray('server_ids'));
    }

    /**
     * One page of the software licensed on this server.
     *
     * @return Page<LicensedSoftware>
     */
    public function software(int $id, int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate(
            $this->path($id) . '/software',
            'licensed_software',
            LicensedSoftware::fromArray(...),
            $page,
            $perPage
        );
    }

    /**
     * Every piece of software licensed on this server, a page at a time.
     *
     * @return \Generator<int, LicensedSoftware>
     */
    public function eachSoftware(int $id, ?int $perPage = null): \Generator
    {
        return $this->apiEach(
            $this->path($id) . '/software',
            'licensed_software',
            LicensedSoftware::fromArray(...),
            $perPage
        );
    }

    /**
     * The cloud-init user-data this server was last initialised with.
     *
     * THE ONE ENDPOINT IN THE API WITH NO ENVELOPE - the body is the object itself. It also
     * routinely carries credentials; Entity\UserData withholds the content from var_dump for
     * that reason.
     */
    public function userData(int $id): UserData
    {
        return UserData::fromArray($this->apiGet($this->path($id) . '/user_data')->body());
    }

    /**
     * URLs for the server's out-of-band console.
     *
     * THE URLS ARE CREDENTIALS AND THEY EXPIRE. Anyone holding one has console access until
     * `expiry` with no further authentication, so fetch one when it is needed rather than
     * storing it, and keep it out of logs. Entity\Console withholds them from var_dump.
     */
    public function console(int $id): Console
    {
        return $this->apiObject($this->path($id) . '/console', 'console', Console::fromArray(...));
    }

    private function path(int $id): string
    {
        if ($id < 1) {
            throw new InvalidArgumentException(sprintf('A server id must be a positive integer; %d was given.', $id));
        }

        return 'servers/' . $id;
    }
}
