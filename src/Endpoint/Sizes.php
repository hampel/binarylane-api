<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * The catalogue of server plans.
 *
 * `/v2/sizes`
 *
 * THE TWO FILTERS CHANGE WHAT THE ANSWER MEANS, and neither is cosmetic:
 *
 *  - `server_id` narrows the list to sizes that server could be RESIZED to, which is a
 *    smaller set than the catalogue and is the only correct list to offer for a resize. The
 *    specification notes it needs authentication, which every request here has.
 *  - `image` narrows each size's `regions` to where that operating system is available ON
 *    that size. WITHOUT IT, THE REGION LISTS ARE WIDER THAN THE TRUTH - a region taken from an
 *    unfiltered list is not a promise that the image you want can be installed there.
 *
 * So `forImage()` is the call to build a create form from, and `forResize()` the one to build
 * a resize form from. `list()` is the raw catalogue, which is the right answer to "what does
 * BinaryLane sell" and the wrong one to "what can I create".
 */
final class Sizes extends Endpoint
{
    public const COLLECTION = 'sizes';

    /**
     * One page of the catalogue.
     *
     * @param  int|null  $serverId  restrict to sizes this server can be resized to
     * @param  int|string|null  $image  narrow each size's regions to where this image is
     *                                  available on it
     * @return Page<Size>
     */
    public function list(
        int $page = 1,
        ?int $perPage = null,
        ?int $serverId = null,
        int|string|null $image = null,
    ): Page {
        return $this->apiPaginate(
            'sizes',
            self::COLLECTION,
            Size::fromArray(...),
            $page,
            $perPage,
            $this->filters($serverId, $image)
        );
    }

    /**
     * Every size, a page at a time.
     *
     * @return \Generator<int, Size>
     */
    public function each(?int $perPage = null, ?int $serverId = null, int|string|null $image = null): \Generator
    {
        return $this->apiEach('sizes', self::COLLECTION, Size::fromArray(...), $perPage, $this->filters($serverId, $image));
    }

    /**
     * The whole catalogue as a list. Small enough to hold, and usually wanted whole.
     *
     * @return list<Size>
     */
    public function all(?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage), false);
    }

    public function count(): int
    {
        return $this->apiCount('sizes', self::COLLECTION);
    }

    /**
     * One size by slug, or null.
     *
     * There is no fetch-one endpoint for a size, so this walks the catalogue. Fetch the list
     * once and search it yourself if you need several.
     */
    public function find(string $slug): ?Size
    {
        $slug = trim($slug);

        if ($slug === '') {
            throw new InvalidArgumentException('A size slug is required.');
        }

        foreach ($this->each() as $size) {
            if ($size->slug === $slug) {
                return $size;
            }
        }

        return null;
    }

    /**
     * The sizes available for an image, with region lists narrowed to where that image
     * actually runs.
     *
     * The list to build a create form from - see the class note.
     *
     * @return list<Size>
     */
    public function forImage(int|string $image, ?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage, null, $image), false);
    }

    /**
     * The sizes an existing server can be resized to.
     *
     * @return list<Size>
     */
    public function forResize(int $serverId, ?int $perPage = null): array
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException('A server id must be a positive integer.');
        }

        return iterator_to_array($this->each($perPage, $serverId), false);
    }

    /**
     * The sizes that can be created in a region right now - offered there, in stock, and
     * available at all.
     *
     * @return list<Size>
     */
    public function availableIn(string $region, int|string|null $image = null): array
    {
        $region = trim($region);
        $sizes = $image === null ? $this->all() : $this->forImage($image);

        return array_values(array_filter($sizes, static fn (Size $size): bool => $size->isAvailableIn($region)));
    }

    /**
     * @return array<string, scalar|null>
     */
    private function filters(?int $serverId, int|string|null $image): array
    {
        $filters = [];

        if ($serverId !== null) {
            $filters['server_id'] = $serverId;
        }

        if ($image !== null) {
            $filters['image'] = is_string($image) ? trim($image) : $image;
        }

        return $filters;
    }
}
