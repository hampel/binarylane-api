<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Connection;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Result\ApiResponse;
use Hampel\BinaryLane\Api\Result\Page;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base class for everything that groups a set of endpoints - this package's own, and
 * anybody else's.
 *
 * This package wraps the whole of BinaryLane's v2 API as published, so unlike a partial
 * client there is no long tail waiting to be reached. Subclassing is still a first-class
 * extension point, for the two things a release cannot anticipate: an endpoint added to the
 * API after this version, and a composite operation an application wants to give a name to.
 *
 *     final class Fleet extends Endpoint
 *     {
 *         public function idleServers(): \Generator
 *         {
 *             return $this->apiEach('servers', 'servers', Server::fromArray(...));
 *         }
 *     }
 *
 *     $binarylane->endpoint(Fleet::class)->idleServers();
 *
 * There is nothing to register, nothing to boot and no container. The class IS the
 * registration, so a third-party package ships one, a consumer type-hints it, and static
 * analysis follows the return type all the way through.
 *
 * What subclassing buys over calling Connection directly is the pagination below. Every
 * collection on this API answers in the same `{<key>: [...], meta: {...}, links: {...}}`
 * envelope, so apiPaginate() and apiEach() work for an endpoint this package has never heard
 * of - the only thing that changes between them is the name of the key.
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Refuse a class name that cannot be constructed as an endpoint.
     *
     * Takes a plain string rather than a `class-string<Endpoint>` deliberately. Both halves
     * of the check are unreachable for a caller with static analysis - which is exactly why
     * the guard cannot be expressed as a type, and why it takes the widest thing a caller can
     * actually pass. A consumer reading a class name out of configuration reaches here; the
     * one writing `endpoint(Servers::class)` never does.
     *
     * The two halves catch different mistakes. The first is a class that is not an Endpoint
     * at all. The second is Endpoint itself, or any abstract subclass - both of which satisfy
     * `class-string<Endpoint>`, so nothing but this stands between them and a fatal on `new`.
     *
     * @param  string  $class
     */
    public static function assertConstructible(string $class): void
    {
        if (!is_subclass_of($class, self::class) || !(new \ReflectionClass($class))->isInstantiable()) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be constructed as an API endpoint: it must be a concrete subclass of %s.',
                $class,
                self::class
            ));
        }
    }

    /**
     * Every helper here carries an `api` prefix, which looks redundant inside a class whose
     * whole job is the API and is not. An endpoint group wants to call its own methods get(),
     * create() and delete() - those are the natural names - and PHP will not let a subclass
     * redeclare an inherited method with a different signature. Prefixing the inherited ones
     * leaves the good names free, for this package's endpoints and for anybody else's.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiGet(string $path, array $query = []): ApiResponse
    {
        return $this->connection->get($path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPost(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->post($path, $payload, $query);
    }

    /**
     * A full replacement - see Connection::put().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPut(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->put($path, $payload, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    protected function apiPatch(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->connection->patch($path, $payload, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $payload
     */
    protected function apiDelete(string $path, array $query = [], array $payload = []): ApiResponse
    {
        return $this->connection->delete($path, $query, $payload);
    }

    /**
     * A read whose answer is one object inside an envelope - `{"server": {...}}`.
     *
     * @template TItem
     * @param  string  $key  the envelope key
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return TItem
     */
    protected function apiObject(string $path, string $key, callable $map, array $query = []): mixed
    {
        return $map($this->apiGet($path, $query)->object($key));
    }

    /**
     * A lookup where "no such thing" is an ordinary answer rather than a failure.
     *
     * ONLY A 404 BECOMES NULL. A 401 is still raised, because a token that has expired and an
     * object that does not exist are different problems and reporting the first as the second
     * sends whoever reads it looking in the wrong place.
     *
     * Be aware of what a 404 covers on this API, though: an id belonging to ANOTHER ACCOUNT
     * answers 404 as well, so null here means "not visible to this token", which is a wider
     * statement than "deleted".
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return TItem|null
     */
    protected function apiFind(string $path, string $key, callable $map, array $query = []): mixed
    {
        $response = $this->apiFindResponse($path, $query);

        if ($response === null) {
            return null;
        }

        $object = $response->object($key);

        return $object === [] ? null : $map($object);
    }

    /**
     * The same lookup, handing back the whole response rather than a mapped object - for an
     * endpoint whose answer is an envelope of several keys rather than one object.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiFindResponse(string $path, array $query = []): ?ApiResponse
    {
        try {
            return $this->apiGet($path, $query);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * One page of a collection.
     *
     * @template TItem
     * @param  string  $key  the key the items are under - `servers`, `domains`, `actions`
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return Page<TItem>
     */
    protected function apiPaginate(
        string $path,
        string $key,
        callable $map,
        int $page = 1,
        ?int $perPage = null,
        array $query = [],
    ): Page {
        $perPage ??= $this->connection->config()->perPage;

        if ($perPage !== null) {
            Page::assertValidPerPage($perPage);
            $query['per_page'] = $perPage;
        }

        $page = max(1, $page);
        $query['page'] = $page;

        return Page::fromResponse($this->apiGet($path, $query), $key, $map, $page);
    }

    /**
     * Every item across every page, fetched a page at a time and only as far as it is
     * consumed - so stopping early stops making requests.
     *
     * IT FOLLOWS THE `next` LINK rather than incrementing a page number, because the API
     * gives one and it is the only statement about whether more exists that is current as of
     * the last response. Incrementing instead would need a second request to discover the
     * end, on every walk.
     *
     * A `per_page` of 0 IS REFUSED HERE even though the API accepts it. It means "send no
     * items and tell me the total", so a walk asking for it would terminate immediately
     * having yielded nothing, which looks exactly like an empty account. Use apiCount() when
     * that is what you meant.
     *
     * A WALK IS A SAMPLE, NOT A SNAPSHOT. Each page is its own request against a collection
     * that can change between them, so a server created while a walk is in progress may
     * appear twice or not at all. Where completeness matters, de-duplicate by id.
     *
     * @template TItem
     * @param  callable(array<string, mixed>): TItem  $map
     * @param  array<string, scalar|null>  $query
     * @return \Generator<int, TItem>
     */
    protected function apiEach(
        string $path,
        string $key,
        callable $map,
        ?int $perPage = null,
        array $query = [],
    ): \Generator {
        $perPage ??= $this->connection->config()->perPage;

        if ($perPage === Page::COUNT_ONLY) {
            $perPage = null;
        }

        $result = $this->apiPaginate($path, $key, $map, 1, $perPage, $query);

        while (true) {
            yield from $result->items;

            $next = $result->nextUri();

            if ($next === null) {
                return;
            }

            $result = Page::fromResponse(
                $this->connection->follow($next),
                $key,
                $map,
                $result->currentPage + 1
            );
        }
    }

    /**
     * How many items a collection holds, in one request that fetches none of them.
     *
     * `per_page=0` is documented as exactly this, and it is the cheap way to answer "does
     * this account have any servers" without paging through them.
     *
     * @param  array<string, scalar|null>  $query
     */
    protected function apiCount(string $path, string $key, array $query = []): int
    {
        $query['per_page'] = Page::COUNT_ONLY;

        return Page::totalOf($this->apiGet($path, $query), $key);
    }
}
