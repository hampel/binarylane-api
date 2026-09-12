<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One routing rule inside a VPC: traffic for `destination` goes to `router`.
 *
 * `router` IS A SERVER'S VPC ADDRESS, not its id - the specification calls it "the server that
 * will receive traffic", and what goes in the field is an address. The server it names has to
 * be in this VPC, and it has to be willing to forward: a server with source and destination
 * checking left on will drop anything not addressed to it, which is exactly the traffic a
 * route entry sends it. See ServerActions::changeSourceAndDestinationCheck().
 *
 * `destination` may be a single address or a CIDR range.
 */
final class RouteEntry implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $router,
        public readonly string $destination,
        public readonly ?string $description = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Route a destination through a server in the VPC.
     */
    public static function to(string $destination, string $router, ?string $description = null): self
    {
        $destination = trim($destination);
        $router = trim($router);

        if ($destination === '') {
            throw new InvalidArgumentException('A route entry needs a destination address or CIDR range.');
        }

        if ($router === '') {
            throw new InvalidArgumentException(
                'A route entry needs the VPC address of the server that will receive the traffic.'
            );
        }

        return new self($router, $destination, $description);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['router'] ?? null) ?? '',
            Cast::string($row['destination'] ?? null) ?? '',
            Cast::string($row['description'] ?? null),
            $row,
        );
    }

    /**
     * Whether this is a default route - everything not otherwise matched.
     */
    public function isDefaultRoute(): bool
    {
        return $this->destination === '0.0.0.0/0';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['router' => $this->router, 'destination' => $this->destination];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }

    public function __toString(): string
    {
        return $this->destination . ' via ' . $this->router;
    }
}
