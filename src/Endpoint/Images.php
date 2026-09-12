<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\ImageDownload;
use Hampel\BinaryLane\Api\Enum\ImageQueryType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Images: operating systems, backups, and uploads.
 *
 * `/v2/images`
 *
 * ONE COLLECTION, TWO VERY DIFFERENT THINGS. `type=distribution` is BinaryLane's catalogue of
 * operating systems - public, addressable by slug, the same for everyone. `type=backup` is
 * this account's own images. Entity\Image covers both and `isDistribution()` says which,
 * because half its fields mean something for only one of them.
 *
 * `private=false` DOES NOTHING. The specification says so outright: "Provide 'true' to only
 * list private images. 'false' has no effect." So it is a filter with one setting, and this
 * endpoint omits the parameter rather than sending a false that reads like a filter and is
 * not - see `privateOnly()`.
 */
final class Images extends Endpoint
{
    public const COLLECTION = 'images';

    /**
     * One image by id, or by slug for a distribution.
     *
     * A BACKUP HAS NO SLUG, so only its numeric id will find it.
     */
    public function get(int|string $image): Image
    {
        return $this->apiObject($this->path($image), 'image', Image::fromArray(...));
    }

    /**
     * One image by id or slug, or null.
     */
    public function find(int|string $image): ?Image
    {
        return $this->apiFind($this->path($image), 'image', Image::fromArray(...));
    }

    /**
     * One page of images.
     *
     * @param  bool  $privateOnly  true restricts to this account's own images. False is not
     *                             sent at all, because the API ignores it
     * @return Page<Image>
     */
    public function list(
        int $page = 1,
        ?int $perPage = null,
        ?ImageQueryType $type = null,
        bool $privateOnly = false,
    ): Page {
        return $this->apiPaginate(
            'images',
            self::COLLECTION,
            Image::fromArray(...),
            $page,
            $perPage,
            $this->filters($type, $privateOnly)
        );
    }

    /**
     * Every image matching the filters, a page at a time.
     *
     * @return \Generator<int, Image>
     */
    public function each(?int $perPage = null, ?ImageQueryType $type = null, bool $privateOnly = false): \Generator
    {
        return $this->apiEach(
            'images',
            self::COLLECTION,
            Image::fromArray(...),
            $perPage,
            $this->filters($type, $privateOnly)
        );
    }

    /**
     * BinaryLane's operating system catalogue, as a list.
     *
     * INCLUDES IMAGES WITH APPLICATIONS PRE-INSTALLED - the specification notes that a
     * distribution query covers those too, so this is wider than "bare operating systems".
     *
     * @return list<Image>
     */
    public function distributions(?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage, ImageQueryType::Distribution), false);
    }

    /**
     * This account's own images - backups and uploads.
     *
     * @return \Generator<int, Image>
     */
    public function backups(?int $perPage = null): \Generator
    {
        return $this->each($perPage, ImageQueryType::Backup);
    }

    public function count(?ImageQueryType $type = null, bool $privateOnly = false): int
    {
        return $this->apiCount('images', self::COLLECTION, $this->filters($type, $privateOnly));
    }

    /**
     * Rename an image, or lock it against replacement.
     *
     * BOTH FIELDS HAVE A THREE-WAY RULE, stated in the specification: omit to leave unchanged,
     * and for the name, an EMPTY STRING clears it. So null and `''` are different answers
     * here, as they are on a DNS record update.
     *
     * LOCKING IS WHAT STOPS THE ROTATION REPLACING A BACKUP, and it has a cost: a locked
     * backup holds its slot, and enough of them stop scheduled backups happening at all - see
     * ThresholdAlertType::LockedBackupSlots. A temporary backup, and one attached to a server,
     * cannot be locked or unlocked.
     */
    public function update(int $imageId, ?string $name = null, ?bool $locked = null): Image
    {
        if ($name === null && $locked === null) {
            throw new InvalidArgumentException(
                'An image update with nothing to change would do nothing; give it a name or a lock state.'
            );
        }

        $payload = [];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if ($locked !== null) {
            $payload['locked'] = $locked;
        }

        return Image::fromArray($this->apiPut($this->path($imageId), $payload)->object('image'));
    }

    /**
     * Time-limited URLs for downloading an image's disks.
     *
     * ONLY USER-CREATED BACKUP IMAGES CAN BE DOWNLOADED. This is also the only operation in
     * the whole specification that declares a 403, which here means the account is not
     * permitted to export - NotPermittedException.
     *
     * THE URLS ARE UNAUTHENTICATED AND THEY ARE THE CONTENTS OF A DISK. See
     * Entity\ImageDownload, which keeps them out of debug output for that reason.
     */
    public function download(int $imageId): ImageDownload
    {
        return $this->apiObject($this->path($imageId) . '/download', 'link', ImageDownload::fromArray(...));
    }

    /**
     * @return array<string, scalar|null>
     */
    private function filters(?ImageQueryType $type, bool $privateOnly): array
    {
        $filters = [];

        if ($type !== null) {
            $filters['type'] = $type->value;
        }

        // Deliberately only sent when true: the API ignores a false, so sending one would
        // suggest a filter that is not being applied.
        if ($privateOnly) {
            $filters['private'] = 'true';
        }

        return $filters;
    }

    private function path(int|string $image): string
    {
        if (is_int($image)) {
            if ($image < 1) {
                throw new InvalidArgumentException(
                    sprintf('An image id must be a positive integer; %d was given.', $image)
                );
            }

            return 'images/' . $image;
        }

        $image = trim($image);

        if ($image === '') {
            throw new InvalidArgumentException('An image id or slug is required.');
        }

        return 'images/' . rawurlencode($image);
    }
}
