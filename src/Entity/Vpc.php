<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A virtual private cloud - a private network servers can be placed in.
 *
 * `ipRange` IS CHOSEN AT CREATION AND CANNOT BE CHANGED AFTERWARDS. Neither the update nor
 * the patch request carries the field, so the range is permanent for the life of the VPC.
 * Choose with room: the default is `10.240.0.0/16`, and the specification notes that because
 * the network is yours alone, any private range will do.
 *
 * `routeEntries` IS A SET REPLACED WHOLE. The specification is explicit that individual
 * entries cannot be patched - "to alter a route entry submit the entire list of route entries
 * you wish to save". Endpoint\Vpcs::setRoutes() is that call, and addRoute() is the read-append-
 * write that the API does not offer.
 */
final class Vpc implements \JsonSerializable
{
    /**
     * BinaryLane's default range, applied when a VPC is created without one.
     */
    public const DEFAULT_IP_RANGE = '10.240.0.0/16';

    /**
     * @param  list<RouteEntry>  $routeEntries
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly string $ipRange = '',
        public readonly array $routeEntries = [],
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
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['ip_range'] ?? null) ?? '',
            Cast::objects($row['route_entries'] ?? null, RouteEntry::fromArray(...)),
            $row,
        );
    }

    /**
     * Whether any route sends traffic for this destination somewhere.
     */
    public function routes(string $destination): bool
    {
        $destination = trim($destination);

        foreach ($this->routeEntries as $entry) {
            if ($entry->destination === $destination) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the VPC has a default route - which decides whether servers in it reach
     * anything they do not have a specific route for.
     */
    public function hasDefaultRoute(): bool
    {
        foreach ($this->routeEntries as $entry) {
            if ($entry->isDefaultRoute()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The route entries with one added or replaced, ready to send back as a whole set.
     *
     * Replacement is by destination, because that is what a route is keyed on: two entries
     * for one destination is a contradiction rather than a pair.
     *
     * @return list<RouteEntry>
     */
    public function withRoute(RouteEntry $entry): array
    {
        $entries = array_values(array_filter(
            $this->routeEntries,
            static fn (RouteEntry $existing): bool => $existing->destination !== $entry->destination
        ));

        $entries[] = $entry;

        return $entries;
    }

    /**
     * The route entries with one destination removed.
     *
     * @return list<RouteEntry>
     */
    public function withoutRoute(string $destination): array
    {
        $destination = trim($destination);

        return array_values(array_filter(
            $this->routeEntries,
            static fn (RouteEntry $existing): bool => $existing->destination !== $destination
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'ip_range' => $this->ipRange,
            'route_entries' => $this->routeEntries,
        ];
    }
}
