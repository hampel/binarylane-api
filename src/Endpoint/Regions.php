<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Region;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * The locations resources can be created in.
 *
 * `/v2/regions`
 *
 * A REGION'S `sizes` AND A SIZE'S `regions` ARE BOTH AVAILABILITY LISTS and they answer the
 * same question from opposite ends. Reading only one of them is how a create request earns a
 * 400 naming the size: `available` on the region is a third condition again, and means the
 * region takes no new resources at all.
 */
final class Regions extends Endpoint
{
    public const COLLECTION = 'regions';

    /**
     * One page of regions.
     *
     * @return Page<Region>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('regions', self::COLLECTION, Region::fromArray(...), $page, $perPage);
    }

    /**
     * Every region, a page at a time.
     *
     * @return \Generator<int, Region>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('regions', self::COLLECTION, Region::fromArray(...), $perPage);
    }

    /**
     * Every region as a list - a short one, and usually wanted whole.
     *
     * @return list<Region>
     */
    public function all(?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage), false);
    }

    public function count(): int
    {
        return $this->apiCount('regions', self::COLLECTION);
    }

    /**
     * One region by slug, or null. There is no fetch-one endpoint, so this reads the list.
     */
    public function find(string $slug): ?Region
    {
        $slug = trim($slug);

        if ($slug === '') {
            throw new InvalidArgumentException('A region slug is required.');
        }

        foreach ($this->each() as $region) {
            if ($region->slug === $slug) {
                return $region;
            }
        }

        return null;
    }

    /**
     * The regions accepting new resources.
     *
     * @return list<Region>
     */
    public function available(): array
    {
        return array_values(array_filter($this->all(), static fn (Region $region): bool => $region->available));
    }

    /**
     * The regions that offer a size and are accepting new resources.
     *
     * STILL NOT THE WHOLE ANSWER: the size's own `regionsOutOfStock` can rule one out today.
     * Sizes::availableIn() applies all three conditions.
     *
     * @return list<Region>
     */
    public function offering(string $size): array
    {
        $size = trim($size);

        return array_values(array_filter(
            $this->all(),
            static fn (Region $region): bool => $region->available && $region->offers($size)
        ));
    }
}
