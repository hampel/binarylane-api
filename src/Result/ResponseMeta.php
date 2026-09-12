<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Psr\Http\Message\ResponseInterface;

/**
 * What arrived alongside a response body.
 *
 * BINARYLANE DOCUMENTS NO RESPONSE HEADERS AT ALL. The specification declares not one -
 * there is no rate-limit budget to read, no request id to quote in a support ticket, and no
 * deprecation notice, at least none that is promised. That is the reason this class keeps
 * every header rather than naming three: a client that guessed at names would have nothing
 * to show when the guess was wrong, and an integration that needs to know what actually came
 * back can read it here without a release of this package.
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
