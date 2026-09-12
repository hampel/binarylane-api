<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A pointer to an action that a create request started.
 *
 * `POST /v2/servers` answers with the server AND a `links.actions` list rather than with an
 * action object - the server is not finished when the call returns, and these are how to
 * follow what is still happening to it. Each carries the action's id, so
 * `Actions::get($link->id)` or `Actions::await($link->id)` is the next step.
 *
 * `href` IS A FULL URL out of a response body. Connection::follow() is what requests one,
 * and it refuses a URL that does not point at the configured API - see Config::ownsUri().
 */
final class ActionLink implements \JsonSerializable
{
    /**
     * @param  string  $rel  what this link is to the thing that returned it
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $rel = '',
        public readonly string $href = '',
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['id'] ?? null) ?? 0,
            Cast::string($row['rel'] ?? null) ?? '',
            Cast::string($row['href'] ?? null) ?? '',
            $row,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'rel' => $this->rel,
            'href' => $this->href,
        ];
    }
}
