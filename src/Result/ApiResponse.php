<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Result;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A successful API response: the decoded body, the status, and the headers that came with it.
 *
 * Returned rather than a bare array because on this API the STATUS is part of the answer.
 * Nearly every mutation can come back two ways - `200` with the action it started, or `202`
 * with nothing at all - and a caller that only ever saw an array could not tell the second
 * from a server that answered with an empty object.
 *
 * EVERY SUCCESSFUL BODY IS AN ENVELOPE, never the object itself. A server read answers
 * `{"server": {...}}`, a list answers `{"servers": [...], "meta": {...}, "links": {...}}`.
 * That is what `object()` and `collection()` are for: they take the key off. The single
 * exception in the whole specification is `GET /v2/servers/{id}/user_data`, which answers
 * with the object bare.
 */
final class ApiResponse implements \JsonSerializable
{
    /**
     * @param  array<mixed>  $data  the decoded JSON body. `[]` for the 204 a delete answers
     *                              with, and for the 202 an accepted server action answers
     *                              with
     */
    public function __construct(
        public readonly array $data,
        public readonly int $status,
        public readonly ResponseMeta $meta,
    ) {
    }

    /**
     * Whether the body carried this key at all, as distinct from carrying it as null.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * One top-level key, or the default when it is absent or null.
     */
    public function value(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * One top-level key that is expected to be an object or a list.
     *
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        return Cast::array($this->data[$key] ?? null);
    }

    /**
     * The object inside the envelope - `object('server')` for `{"server": {...}}`.
     *
     * Answers `[]` rather than raising when the key is absent, because the case that produces
     * it is a 202 with no body rather than a malformed response, and the endpoint methods
     * turn that into a null return.
     *
     * @return array<string, mixed>
     */
    public function object(string $key): array
    {
        return Cast::object($this->data[$key] ?? null);
    }

    /**
     * The whole body as an object, for the one endpoint that does not use an envelope.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return Cast::object($this->data);
    }

    /**
     * The list inside the envelope - `collection('servers')` for `{"servers": [...]}` - with
     * anything that is not an object dropped.
     *
     * @return list<array<string, mixed>>
     */
    public function collection(string $key): array
    {
        $rows = [];

        foreach ($this->array($key) as $row) {
            if (is_array($row)) {
                $rows[] = Cast::object($row);
            }
        }

        return $rows;
    }

    /**
     * `meta.total` - how many items exist across every page, not how many are on this one.
     *
     * Null when the response carried no `meta`, which is every non-paginated endpoint.
     */
    public function total(): ?int
    {
        return Cast::int(Cast::object($this->data['meta'] ?? null)['total'] ?? null);
    }

    /**
     * Whether the response carried no body.
     *
     * TRUE IS A SUCCESSFUL ANSWER ON THIS API, not a failure: a delete answers 204, and a
     * server action that was queued rather than started answers 202. Both are bodiless by
     * design. A status that should have had a body and did not never reaches here - it is
     * raised as a MalformedResponseException instead.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Whether the API took the request but has not answered with the result of it - HTTP 202.
     *
     * The one status on this API that means "accepted, ask again later". See Endpoint\Actions
     * for how to find the action it started.
     */
    public function isAccepted(): bool
    {
        return $this->status === 202;
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
