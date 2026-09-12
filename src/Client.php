<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api;

use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Authentication\Authentication;
use Hampel\BinaryLane\Api\Endpoint\Account;
use Hampel\BinaryLane\Api\Endpoint\Actions;
use Hampel\BinaryLane\Api\Endpoint\Billing;
use Hampel\BinaryLane\Api\Endpoint\DataUsages;
use Hampel\BinaryLane\Api\Endpoint\DomainRecords;
use Hampel\BinaryLane\Api\Endpoint\Domains;
use Hampel\BinaryLane\Api\Endpoint\Images;
use Hampel\BinaryLane\Api\Endpoint\LoadBalancers;
use Hampel\BinaryLane\Api\Endpoint\Regions;
use Hampel\BinaryLane\Api\Endpoint\ReverseNames;
use Hampel\BinaryLane\Api\Endpoint\SampleSets;
use Hampel\BinaryLane\Api\Endpoint\Sizes;
use Hampel\BinaryLane\Api\Endpoint\SoftwareCatalogue;
use Hampel\BinaryLane\Api\Endpoint\SshKeys;
use Hampel\BinaryLane\Api\Endpoint\Vpcs;
use Hampel\BinaryLane\Api\Endpoint\Endpoint;
use Hampel\BinaryLane\Api\Endpoint\ServerActions;
use Hampel\BinaryLane\Api\Endpoint\Servers;
use Hampel\BinaryLane\Api\Entity\Account as AccountEntity;
use Hampel\BinaryLane\Api\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point. Hand it a token and any PSR-18 client:
 *
 *     $guzzle  = new GuzzleHttp\Client();
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *
 *     $binarylane = new Client(new Config(), new ApiToken($token), $guzzle, $factory, $factory);
 *
 * Or, for the ordinary case where the answer to every construction question is the default:
 *
 *     $binarylane = Client::withToken($token, $guzzle);
 *
 *     $binarylane->verify();                          // does this token work?
 *     $binarylane->servers()->list();
 *
 * ONE TOKEN CAN DO EVERYTHING THE ACCOUNT CAN DO. BinaryLane issues a single kind of API
 * token: no scopes, no expiry, and no read-only variant. So a client constructed for a
 * monitoring job is a client that could cancel a server, and the separation has to be yours -
 * see Authentication\ApiToken.
 */
final class Client
{
    private readonly Connection $connection;

    /** @var array<class-string<Endpoint>, Endpoint> */
    private array $endpoints = [];

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros', whichever is installed;
     *         see Psr17Discovery. Pass them to choose, or when none of those is present.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection(
            $this->config,
            $this->authentication,
            $client,
            $requestFactory,
            $streamFactory,
            $this->logger
        );
    }

    /**
     * The short form: an API token, the default configuration, and a transport.
     *
     * Everything the long constructor takes is still available on it; this exists because
     * naming a Config and an ApiToken to accept both defaults is ceremony, and ceremony in an
     * example is what gets copied.
     */
    public static function withToken(
        #[\SensitiveParameter] string $token,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            new Config(),
            new ApiToken($token),
            $client,
            $requestFactory,
            $streamFactory,
            $logger ?? new NullLogger()
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * Does this token work - in one request that changes nothing.
     *
     * The call to make at startup, before anything that matters. Raises
     * NotAuthenticatedException for a token that is not valid, and otherwise answers with the
     * account, whose `status` is worth looking at: a token can be perfectly valid on an
     * account that is `locked` or has no payment method, and everything else will then fail
     * with a 400 about a field.
     */
    public function verify(): AccountEntity
    {
        return $this->account()->get();
    }

    /**
     * The same account and transport, under a different credential.
     *
     * A new client rather than a mutation, so two credentials in one long-running process - a
     * job that walks several accounts - cannot leak into each other's requests. Endpoints are
     * not carried over: they hold the connection this one is replacing.
     */
    public function withCredential(Authentication $authentication): self
    {
        return new self(
            $this->config,
            $authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * The same credential and transport, under a different configuration - a different page
     * size, or a base URI pointed at a local fixture.
     */
    public function withConfig(Config $config): self
    {
        return new self(
            $config,
            $this->authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * Any Endpoint subclass, constructed and memoised.
     *
     * This is the extension point. A third-party package ships an Endpoint subclass for
     * something added to the API after this release - or for a composite operation of its
     * own - a consumer names the class, and static analysis follows the return type through.
     * There is nothing to register, no container and no string keys. The named accessors
     * below are the same mechanism with a shorter name.
     *
     * @template T of Endpoint
     * @param  class-string<T>  $class
     * @return T
     */
    public function endpoint(string $class): Endpoint
    {
        if (!isset($this->endpoints[$class])) {
            Endpoint::assertConstructible($class);

            $this->endpoints[$class] = new $class($this->connection, $this->logger);
        }

        /** @var T $endpoint */
        $endpoint = $this->endpoints[$class];

        return $endpoint;
    }

    /**
     * The account the token belongs to.
     */
    public function account(): Account
    {
        return $this->endpoint(Account::class);
    }

    /**
     * Actions - what the API is doing, and whether it worked.
     *
     * The endpoint most calls end up at, because nearly every mutation on this API answers
     * with an action rather than a result. `actions()->await()` is the loop.
     */
    public function actions(): Actions
    {
        return $this->endpoint(Actions::class);
    }

    /**
     * DNS zones.
     *
     * `domains()->records('example.com')` binds a zone for a sequence of record calls.
     */
    public function domains(): Domains
    {
        return $this->endpoint(Domains::class);
    }

    /**
     * DNS records, with the zone name as the first argument to every call.
     */
    public function records(): DomainRecords
    {
        return $this->endpoint(DomainRecords::class);
    }

    /**
     * Servers: reading them, creating them, cancelling them.
     */
    public function servers(): Servers
    {
        return $this->endpoint(Servers::class);
    }

    /**
     * Everything you can do TO a server - power, resize, rebuild, backups, disks, networking.
     *
     * Separate from servers() because the API separates them: these are all one POST with a
     * `type` discriminator, and every one of them answers with an action rather than a result.
     */
    public function serverActions(): ServerActions
    {
        return $this->endpoint(ServerActions::class);
    }

    /**
     * Images: BinaryLane's operating system catalogue, and this account's own backups.
     */
    public function images(): Images
    {
        return $this->endpoint(Images::class);
    }

    /**
     * SSH keys held on the account, at `/v2/account/keys`.
     */
    public function sshKeys(): SshKeys
    {
        return $this->endpoint(SshKeys::class);
    }

    /**
     * The catalogue of server plans.
     */
    public function sizes(): Sizes
    {
        return $this->endpoint(Sizes::class);
    }

    /**
     * The locations resources can be created in.
     */
    public function regions(): Regions
    {
        return $this->endpoint(Regions::class);
    }

    /**
     * Licensable software - the catalogue, as distinct from what a server has licensed.
     */
    public function software(): SoftwareCatalogue
    {
        return $this->endpoint(SoftwareCatalogue::class);
    }

    /**
     * Load balancers - HTTP and HTTPS only, regional or anycast.
     */
    public function loadBalancers(): LoadBalancers
    {
        return $this->endpoint(LoadBalancers::class);
    }

    /**
     * Virtual private clouds, their route tables and their members.
     */
    public function vpcs(): Vpcs
    {
        return $this->endpoint(Vpcs::class);
    }

    /**
     * Balance and invoices. The specification files these under a `Customers` tag.
     */
    public function billing(): Billing
    {
        return $this->endpoint(Billing::class);
    }

    /**
     * Data transfer used this billing period - pooled across the account's servers.
     */
    public function dataUsages(): DataUsages
    {
        return $this->endpoint(DataUsages::class);
    }

    /**
     * Performance and usage samples for a server.
     */
    public function sampleSets(): SampleSets
    {
        return $this->endpoint(SampleSets::class);
    }

    /**
     * The account-wide IPv6 reverse nameservers.
     */
    public function reverseNames(): ReverseNames
    {
        return $this->endpoint(ReverseNames::class);
    }

    /**
     * For anything this package's endpoints do not cover - a path added to the API after this
     * release, or a request that needs assembling by hand.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
