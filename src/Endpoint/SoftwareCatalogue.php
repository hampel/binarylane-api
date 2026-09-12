<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Software;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Licensable software - cPanel, Plesk, Windows editions.
 *
 * `/v2/software`
 *
 * NAMED FOR WHAT IT IS RATHER THAN FOR THE PATH, because `Software` is the entity and one of
 * them has to give. This is the CATALOGUE - what can be licensed at all;
 * `Servers::software()` is what a particular server has licensed.
 *
 * THREE RULES DECIDE HOW MANY LICENCES ARE VALID - a minimum, a maximum and a step - and a
 * fourth decides whether two products can be held together at all, through
 * Software::$group. Request\License::forSoftware() applies the first three and
 * Request\License::conflictIn() the fourth, both before a request is spent.
 *
 * `forOperatingSystem()` IS THE CALL THAT MATTERS FOR A CREATE: software is licensed against
 * an image, and the catalogue at large includes products that image cannot take.
 */
final class SoftwareCatalogue extends Endpoint
{
    public const COLLECTION = 'software';

    /**
     * One product by id.
     */
    public function get(int $softwareId): Software
    {
        return $this->apiObject($this->path($softwareId), 'software', Software::fromArray(...));
    }

    /**
     * One product by id, or null.
     */
    public function find(int $softwareId): ?Software
    {
        return $this->apiFind($this->path($softwareId), 'software', Software::fromArray(...));
    }

    /**
     * One page of the catalogue.
     *
     * @return Page<Software>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('software', self::COLLECTION, Software::fromArray(...), $page, $perPage);
    }

    /**
     * Every product, a page at a time.
     *
     * @return \Generator<int, Software>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('software', self::COLLECTION, Software::fromArray(...), $perPage);
    }

    /**
     * The whole catalogue as a list.
     *
     * @return list<Software>
     */
    public function all(?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage), false);
    }

    public function count(): int
    {
        return $this->apiCount('software', self::COLLECTION);
    }

    /**
     * The catalogue keyed by id - what Request\License::conflictIn() takes.
     *
     * @return array<int, Software>
     */
    public function byId(?int $perPage = null): array
    {
        $catalogue = [];

        foreach ($this->each($perPage) as $software) {
            $catalogue[$software->id] = $software;
        }

        return $catalogue;
    }

    /**
     * What can be licensed on a particular operating system image.
     *
     * @param  int|string  $image  an operating system id or slug. A BACKUP image has no slug,
     *                             so only an id will do for one
     * @return \Generator<int, Software>
     */
    public function forOperatingSystem(int|string $image, ?int $perPage = null): \Generator
    {
        return $this->apiEach(
            'software/operating_system/' . $this->reference($image),
            self::COLLECTION,
            Software::fromArray(...),
            $perPage
        );
    }

    /**
     * The same, as a list.
     *
     * @return list<Software>
     */
    public function availableFor(int|string $image, ?int $perPage = null): array
    {
        return iterator_to_array($this->forOperatingSystem($image, $perPage), false);
    }

    private function reference(int|string $image): string
    {
        if (is_int($image)) {
            if ($image < 1) {
                throw new InvalidArgumentException(
                    sprintf('An operating system id must be a positive integer; %d was given.', $image)
                );
            }

            return (string) $image;
        }

        $image = trim($image);

        if ($image === '') {
            throw new InvalidArgumentException('An operating system id or slug is required.');
        }

        return rawurlencode($image);
    }

    private function path(int $softwareId): string
    {
        if ($softwareId < 1) {
            throw new InvalidArgumentException(
                sprintf('A software id must be a positive integer; %d was given.', $softwareId)
            );
        }

        return 'software/' . $softwareId;
    }
}
