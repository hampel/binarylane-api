<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A location resources can be created in.
 *
 * THE SLUG IS THE IDENTIFIER EVERYWHERE ELSE. `per`, `syd`, `mel` and so on are what a
 * create request takes, what a server reports, and what a size's availability is expressed
 * in; the name is for showing a person.
 *
 * `sizes` IS THE AVAILABILITY LIST AND IT IS PER REGION. A size that exists is not a size
 * you can use here - `Sizes::list()` is the catalogue, and this is which of it this location
 * actually offers. Reading only the catalogue is how a create request earns a 400 naming the
 * size.
 *
 * `available` IS SEPARATE AGAIN: false means the region takes no new resources at all, whatever
 * its size list says. Servers already there keep running.
 */
final class Region implements \JsonSerializable
{
    /**
     * @param  list<string>  $sizes  the slugs of the sizes available HERE
     * @param  list<string>  $features  what resources in this region can do
     * @param  list<string>  $nameServers  the nameservers available to resources here
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name = '',
        public readonly array $sizes = [],
        public readonly bool $available = false,
        public readonly array $features = [],
        public readonly array $nameServers = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['slug'] ?? null) ?? '',
            Cast::string($row['name'] ?? null) ?? '',
            Cast::strings($row['sizes'] ?? null),
            Cast::bool($row['available'] ?? null) ?? false,
            Cast::strings($row['features'] ?? null),
            Cast::strings($row['name_servers'] ?? null),
            $row,
        );
    }

    /**
     * Whether this region offers a given size slug.
     */
    public function offers(string $size): bool
    {
        return in_array($size, $this->sizes, true);
    }

    /**
     * Whether this region advertises a named feature.
     */
    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * Whether a new resource can be created here right now.
     */
    public function acceptsNewResources(): bool
    {
        return $this->available;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'slug' => $this->slug,
            'name' => $this->name,
            'sizes' => $this->sizes,
            'available' => $this->available,
            'features' => $this->features,
            'name_servers' => $this->nameServers,
        ];
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
