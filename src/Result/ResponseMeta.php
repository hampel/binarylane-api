<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Psr\Http\Message\ResponseInterface;

/**
 * What arrived alongside a response body.
 *
 * BINARYLANE DOCUMENTS NO RESPONSE HEADERS AT ALL, AND SENDS AT LEAST ONE. The specification
 * declares not a single header - no rate-limit budget, no request id to quote in a support
 * ticket, no deprecation notice. The live API nevertheless returns `X-Spec-Version`, measured
 * on 12 September 2026 as `0.40.0`, matching the `info.version` of the published
 * specification.
 *
 * That gap is the whole reason this class keeps every header rather than naming three. A
 * client that had guessed at names would have missed the one header that exists, and an
 * integration that needs to know what actually came back can read it here without waiting for
 * a release of this package.
 *
 * Keys are lowercased, because HTTP header names are case-insensitive and a caller matching
 * on `X-Request-Id` should not miss `x-request-id`.
 */
final class ResponseMeta
{
    /**
     * @param  array<string, string>  $headers  lowercased name to comma-joined value
     */
    public function __construct(
        public readonly array $headers = [],
    ) {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        $headers = [];

        foreach (array_keys($response->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $response->getHeaderLine((string) $name);
        }

        return new self($headers);
    }

    /**
     * One header, case-insensitively, or null when it was not sent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): ?string
    {
        return $this->header('Content-Type');
    }

    /**
     * `X-Spec-Version` - which version of the API specification answered.
     *
     * UNDOCUMENTED, AND THE ONLY WAY TO NOTICE A CHANGE. BinaryLane's own introduction warns
     * that "breaking changes are possible without the version changing" - by which it means
     * the API version, `v2`. This header carries the SPECIFICATION version, which does move:
     * `0.40.0` at the time of writing. Recording it alongside anything surprising is the
     * cheapest way to find out later whether the API changed underneath you or you were
     * always wrong.
     *
     * Null when the header was not sent, which nothing promises it will be.
     */
    public function specVersion(): ?string
    {
        return $this->header('X-Spec-Version');
    }

    /**
     * `Retry-After` in seconds, when it was sent as a plain integer.
     *
     * Null means "no usable number", not "no header": the header's other legal form is an
     * HTTP date, which is not parsed here.
     */
    public function retryAfter(): ?int
    {
        $value = trim($this->header('Retry-After') ?? '');

        return $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->headers;
    }
}
