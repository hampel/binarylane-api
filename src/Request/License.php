<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\Software;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * A request for some number of licences of one software product.
 *
 * The specification's `License` schema - two fields, and both of them constrained by the
 * Software they refer to. forSoftware() validates against it before a request is spent
 * finding out; the bare constructor does not, for the case where the catalogue has not been
 * fetched.
 *
 * A LICENCE SET IS REPLACED WHOLE by ChangeLicenses, like most set-shaped things on this
 * API - so removing one product means sending the others, and sending an empty list removes
 * them all.
 */
final class License implements \JsonSerializable
{
    public function __construct(
        public readonly int $softwareId,
        public readonly int $count,
    ) {
        if ($softwareId < 1) {
            throw new InvalidArgumentException('A licence needs the id of the software it is for.');
        }

        if ($count < 0) {
            throw new InvalidArgumentException('A licence count cannot be negative.');
        }
    }

    /**
     * The same thing, checked against the product's own rules first.
     *
     * Refuses a count that breaks the minimum, the maximum or the step, and refuses software
     * that is no longer enabled - all three of which the API answers with a 400 naming a
     * field rather than the rule.
     */
    public static function forSoftware(Software $software, int $count): self
    {
        if (!$software->enabled) {
            throw new InvalidArgumentException(sprintf(
                '"%s" (software %d) is not enabled, so no new licences for it can be bought. '
                    . 'Existing licences keep working.',
                $software->name,
                $software->id
            ));
        }

        if (!$software->allowsCount($count)) {
            $suggestion = $software->roundUpCount($count);

            throw new InvalidArgumentException(sprintf(
                '"%s" accepts between %d and %d licences in multiples of %d; %d was asked for.%s',
                $software->name,
                $software->minimumLicenceCount,
                $software->maximumLicenceCount,
                $software->licenceStepCount,
                $count,
                $suggestion === null ? '' : sprintf(' The nearest acceptable count is %d.', $suggestion)
            ));
        }

        return new self($software->id, $count);
    }

    /**
     * Whether a set of licences asks for two products that cannot be held together.
     *
     * Needs the catalogue, because the mutual exclusion lives on Software::$group and a
     * licence request carries only ids. Answers the conflicting pair, or null.
     *
     * @param  list<self>  $licenses
     * @param  array<int, Software>  $catalogue  software keyed by id - what
     *                                           Endpoint\SoftwareCatalogue::byId() returns
     * @return array{Software, Software}|null
     */
    public static function conflictIn(array $licenses, array $catalogue): ?array
    {
        $products = [];

        foreach ($licenses as $license) {
            $software = $catalogue[$license->softwareId] ?? null;

            if ($software !== null) {
                $products[] = $software;
            }
        }

        foreach ($products as $i => $first) {
            foreach (array_slice($products, $i + 1) as $second) {
                if ($first->conflictsWith($second)) {
                    return [$first, $second];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return ['software_id' => $this->softwareId, 'count' => $this->count];
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
