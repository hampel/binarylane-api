<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * One page of a collection, and the pagination that came with it.
 *
 * Every paginated endpoint on this API answers in the same envelope, differing only in the
 * name of the key holding the items:
 *
 *     {"servers": [...], "meta": {"total": 247}, "links": {"pages": {"next": "..."}}}
 *
 * `meta.total` IS THE TOTAL ACROSS EVERY PAGE, not the size of this one. `count()` is the
 * size of this one. They are easy to read as each other, and on a first page of 20 out of 20
 * they are briefly equal, which is how the confusion survives being tested.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * WHAT YOU GET IF YOU DO NOT ASK, and it is smaller than most people expect.
     *
     * Twenty. A list of servers, images or actions that looks complete at a glance is very
     * often the first twenty of several hundred - `meta.total` is the field that says so, and
     * `Endpoint::apiEach()` is the way not to have to care.
     */
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 200;

    /**
     * `per_page=0` IS LEGAL AND RETURNS NO ITEMS.
     *
     * The specification documents it as "use 0 to return only the total count", so it is a
     * deliberate feature rather than an off-by-one: the response carries `meta.total` and an
     * empty collection. Endpoint::apiCount() is that call with a name on it. Passing it by
     * accident to a list method is the trap - an empty page that is not the end of the data
     * and does not say so.
     */
    public const COUNT_ONLY = 0;

    /**
     * @param  list<T>  $items
     * @param  int  $total  the API's `meta.total`, across every page
     * @param  int  $currentPage  the page that was ASKED for. The response does not say which
     *                            page it is; only the links imply it
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $currentPage,
        public readonly PageLinks $links,
    ) {
    }

    /**
     * @template TItem
     * @param  ApiResponse  $response  the whole response, because the items, the total and
     *                                 the links are three separate keys of one body
     * @param  string  $key  the key the items are under - `servers`, `domains`, `actions`
     * @param  callable(array<string, mixed>): TItem  $map  how to build one item
     * @return self<TItem>
     */
    public static function fromResponse(ApiResponse $response, string $key, callable $map, int $currentPage = 1): self
    {
        $items = [];

        foreach ($response->collection($key) as $row) {
            $items[] = $map($row);
        }

        return new self(
            $items,
            $response->total() ?? count($items),
            max(1, $currentPage),
            PageLinks::from($response->value('links')),
        );
    }

    /**
     * Whether another page exists.
     *
     * ANSWERED BY THE `next` LINK, not by arithmetic over `meta.total`. The two disagree in
     * the case that matters: a collection changing while it is walked. The link is what the
     * API just said; the arithmetic is what was true when the first page was fetched.
     */
    public function hasMore(): bool
    {
        return $this->links->hasNext();
    }

    /**
     * The URI of the next page, to be requested as-is.
     *
     * Null on the last page. Connection follows these rather than rebuilding them, which is
     * why Config::ownsUri() exists - a link is a URL out of a response body, and it is
     * requested with the account's bearer token attached.
     */
    public function nextUri(): ?string
    {
        return $this->links->next;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * How many items are on THIS page. `$page->total` is how many there are altogether.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The first item, or null on an empty page - for a lookup that is a filtered list rather
     * than a fetch by id, which several of this API's are.
     *
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @return list<T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }

    /**
     * Refuse a page size the API will refuse, before spending a request finding out.
     *
     * Zero is allowed through deliberately - it is the documented "count only" request. What
     * is refused is a negative number and anything above 200.
     */
    public static function assertValidPerPage(int $perPage): void
    {
        if ($perPage < self::COUNT_ONLY || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'BinaryLane accepts a page size between %d and %d; %d was asked for. %d is '
                    . 'legal and means "tell me the total and send no items".',
                self::COUNT_ONLY,
                self::MAX_PER_PAGE,
                $perPage,
                self::COUNT_ONLY
            ));
        }
    }

    /**
     * The total out of a response, for a `per_page=0` request where the items were never
     * wanted.
     *
     * Falls back to counting what did arrive, which is what a non-paginated endpoint - one
     * with no `meta` at all - would produce if this were ever pointed at one.
     */
    public static function totalOf(ApiResponse $response, string $key): int
    {
        return $response->total() ?? count($response->collection($key));
    }
}
