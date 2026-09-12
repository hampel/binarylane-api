<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The `links.pages` object: where the first, previous, next and last pages are.
 *
 *     {"links": {"pages": {"next": "https://api.binarylane.com.au/v2/servers?page=2&per_page=20",
 *                          "last": "https://api.binarylane.com.au/v2/servers?page=9&per_page=20"}}}
 *
 * THE WHOLE `links` OBJECT IS ABSENT WHEN THERE IS ONLY ONE PAGE, and each link inside it is
 * absent when it does not apply - no `prev` on the first page, no `next` on the last. So
 * "there are no links" and "there is one page" are the same response, and this class answers
 * both as an empty set rather than as a missing thing the caller has to check for.
 *
 * The page NUMBERS are derived from the URLs rather than given. That is why `lastPage()` can
 * answer null: it means the `last` link was absent or carried no `page` parameter, which is
 * the ordinary single-page case - not that the collection is unbounded.
 */
final class PageLinks implements \JsonSerializable
{
    public function __construct(
        public readonly ?string $first = null,
        public readonly ?string $prev = null,
        public readonly ?string $next = null,
        public readonly ?string $last = null,
    ) {
    }

    /**
     * @param  mixed  $links  the `links` value straight out of a response body, which is
     *                        absent on a single-page collection and null-typed in the schema
     */
    public static function from(mixed $links): self
    {
        $pages = Cast::object(Cast::object($links)['pages'] ?? null);

        return new self(
            self::link($pages['first'] ?? null),
            self::link($pages['prev'] ?? null),
            self::link($pages['next'] ?? null),
            self::link($pages['last'] ?? null),
        );
    }

    public function hasNext(): bool
    {
        return $this->next !== null;
    }

    public function hasPrev(): bool
    {
        return $this->prev !== null;
    }

    /**
     * The number of the last page, read out of the `last` link.
     *
     * Null on a single-page collection, and on any response whose `last` link is not shaped
     * the way the API writes it today. A caller wanting "is there more" should ask
     * `hasNext()`, which is answered by the response rather than derived from it.
     */
    public function lastPage(): ?int
    {
        return self::pageOf($this->last);
    }

    public function nextPage(): ?int
    {
        return self::pageOf($this->next);
    }

    /**
     * Whether the API sent any links at all.
     */
    public function isEmpty(): bool
    {
        return $this->first === null && $this->prev === null
            && $this->next === null && $this->last === null;
    }

    /**
     * The `page` query parameter of one of these URLs.
     */
    public static function pageOf(?string $uri): ?int
    {
        if ($uri === null) {
            return null;
        }

        $query = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $parameters);

        $page = Cast::int($parameters['page'] ?? null);

        return $page !== null && $page > 0 ? $page : null;
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'first' => $this->first,
            'prev' => $this->prev,
            'next' => $this->next,
            'last' => $this->last,
        ], static fn (?string $value): bool => $value !== null);
    }

    private static function link(mixed $value): ?string
    {
        $link = Cast::string($value);

        return $link !== null && trim($link) !== '' ? trim($link) : null;
    }
}
