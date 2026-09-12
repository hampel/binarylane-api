<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Which API we are talking to, and how its URLs are built.
 *
 * Unlike a self-hosted API there is exactly one BinaryLane, so this is constructible with no
 * arguments at all and usually should be:
 *
 *     new Config()                 // https://api.binarylane.com.au/v2
 *     new Config(perPage: 200)     // the same, asking for the largest page the API allows
 *
 * The base URI is settable for the two cases that need it - a recorded fixture served
 * locally, and an outbound proxy that terminates the connection - and for nothing else.
 */
final class Config
{
    public const DEFAULT_HOST = 'api.binarylane.com.au';

    /**
     * The only version there has ever been.
     *
     * It is a URL segment, so it is part of every path in the specification: `/v2/servers`,
     * `/v2/domains`. The constructor takes it anyway - a `v3` would be a URL change and
     * nothing else, and a package that hard-coded the segment would need a release to reach
     * it - but there is nothing to choose between today.
     */
    public const VERSION = 'v2';

    public readonly string $baseUri;

    /**
     * @param  string|null  $baseUri  the API root WITHOUT the version segment, e.g.
     *                                `https://api.binarylane.com.au`. Null uses BinaryLane's
     *                                own host
     * @param  int|null  $perPage  how many items a list request asks for when the caller does
     *                             not say. Null uses the API's own default of 20 - see Page,
     *                             because that default is smaller than it looks
     * @param  string  $version  the API version segment
     */
    public function __construct(
        ?string $baseUri = null,
        public readonly ?int $perPage = null,
        public readonly string $version = self::VERSION,
    ) {
        if (trim($version) === '' || str_contains($version, '/')) {
            throw new InvalidArgumentException(sprintf(
                'The BinaryLane API version must be a single URL segment such as "%s"; "%s" is not.',
                self::VERSION,
                $version
            ));
        }

        if ($perPage !== null) {
            Page::assertValidPerPage($perPage);
        }

        $baseUri = trim($baseUri ?? 'https://' . self::DEFAULT_HOST);

        if ($baseUri === '') {
            throw new InvalidArgumentException('The BinaryLane API base URI cannot be empty.');
        }

        if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
            throw new InvalidArgumentException(sprintf(
                'The BinaryLane API base URI must be absolute, with a scheme: "%s" is not.',
                $baseUri
            ));
        }

        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * Turn a path into an absolute URI.
     *
     * Three shapes arrive here. A relative path - `servers/1234/actions` - is the usual one.
     * An absolute URI passes through untouched, so a URL the API itself produced - a
     * `links.pages.next`, an action's `href` - can be fed straight back in. And a path that
     * already carries the version segment is normalised rather than doubled, which is what
     * makes `/v2/servers` copied out of the documentation work as readily as `servers`.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function resolve(string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $uri = $path;
        } else {
            $path = ltrim($path, '/');

            if ($path === $this->version) {
                $path = '';
            } elseif (str_starts_with($path, $this->version . '/')) {
                $path = substr($path, strlen($this->version) + 1);
            }

            $uri = rtrim($this->baseUri . '/' . $this->version . '/' . $path, '/');
        }

        $query = array_filter($query, static fn (mixed $value): bool => $value !== null);

        if ($query !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $uri;
    }

    /**
     * The host the client will talk to, for anything that needs to name the endpoint - a log
     * line, an exception message, a settings screen.
     */
    public function host(): string
    {
        return parse_url($this->baseUri, PHP_URL_HOST) ?: self::DEFAULT_HOST;
    }

    /**
     * Whether a URI the API handed back points at the API we are configured for.
     *
     * THE PAGINATION LINKS ARE FOLLOWED, so this is a real check rather than a formality. A
     * walk over a collection reads `links.pages.next` out of the response and requests it
     * with the account's bearer token attached - which is exactly the shape of an SSRF, if
     * the URL in the response can be made to point somewhere else. A body that named another
     * host would send the credential there.
     *
     * The scheme and the host must both match. The port is not compared, because the
     * configured base URI usually omits it and a link usually does too, so comparing it would
     * reject every link on a base URI written with an explicit `:443`.
     */
    public function ownsUri(string $uri): bool
    {
        $parts = parse_url($uri);
        $base = parse_url($this->baseUri);

        if (!is_array($parts) || !is_array($base)) {
            return false;
        }

        return isset($parts['host'], $base['host'])
            && strcasecmp((string) $parts['host'], (string) $base['host']) === 0
            && strcasecmp((string) ($parts['scheme'] ?? ''), (string) ($base['scheme'] ?? '')) === 0;
    }
}
