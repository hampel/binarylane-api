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
 *  - `image` RESTRICTS THE CATALOGUE TO SIZES THAT OPERATING SYSTEM CAN ACTUALLY BE INSTALLED
 *    ON. Measured on 12 September 2026: an unfiltered list of 21 sizes came back as 17 for
 *    `windows-2025` and 13 for `windows-2022-sql-2019-std`, the dropped ones being the storage
 *    and dedicated plans those images do not fit. An undemanding image dropped none.
 *
 *    The specification describes this as narrowing each size's `regions` to "only valid
 *    regions for the size and operating system". The two readings reconcile: a size left with
 *    no valid region is omitted altogether rather than returned with an empty region list.
 *    Region lists on the sizes that SURVIVE were unchanged in every case measured - though
 *    every distribution on that account was offered in all six regions, so there was no size
 *    that should have been kept and narrowed. Expect either.
 *
 * So `forImage()` is the call to build a create form from, and `forResize()` the one to build
 * a resize form from. `list()` is the raw catalogue, which is the right answer to "what does
 * BinaryLane sell" and the wrong one to "what can I create" - it contains sizes the image you
 * have in mind cannot be installed on at all.
 */
final class Sizes extends Endpoint
{
    public const COLLECTION = 'sizes';

    /**
     * One page of the catalogue.
     *
     * @param  int|null  $serverId  restrict to sizes this server can be resized to
     * @param  int|string|null  $image  restrict to sizes this image can be installed on, and
     *                                  narrow their regions to where it is available
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
     * The sizes an image can actually be installed on.
     *
     * THE LIST TO BUILD A CREATE FORM FROM. The unfiltered catalogue contains sizes that will
     * refuse the image outright - eight of twenty-one for a SQL Server edition, measured - and
     * a size taken from it is not a promise. See the class note.
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
