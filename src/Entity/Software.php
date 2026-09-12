<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A licensable software product - cPanel, Plesk, a Windows edition.
 *
 * THREE RULES GOVERN HOW MANY LICENCES YOU MAY BUY and all three are enforced: at least
 * `minimumLicenceCount`, at most `maximumLicenceCount`, and in multiples of
 * `licenceStepCount`. allowsCount() applies all three, which is worth doing before a create
 * request rather than reading a 400 about it.
 *
 * `group` IS A MUTUAL EXCLUSION. Software sharing a group cannot be licensed together - two
 * control panels, for instance - so a licence set has to be checked across its members, not
 * one at a time. Request\License::conflicts() is that check.
 *
 * `supportedOperatingSystems` IS A LIST OF IMAGE SLUGS, so it can be checked against an
 * image's `slug` directly - and a BACKUP image has no slug, which means software cannot be
 * matched to a server being rebuilt from one without looking at what it was built from.
 *
 * `enabled` false means no NEW licences: existing ones keep working, which is how a product
 * is withdrawn.
 */
final class Software implements \JsonSerializable
{
    /**
     * @param  list<string>  $supportedOperatingSystems  image slugs
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly string $description = '',
        public readonly bool $enabled = false,
        public readonly float $costPerLicencePerMonth = 0.0,
        public readonly int $minimumLicenceCount = 0,
        public readonly int $maximumLicenceCount = 0,
        public readonly int $licenceStepCount = 1,
        public readonly ?string $group = null,
        public readonly array $supportedOperatingSystems = [],
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
            Cast::string($row['description'] ?? null) ?? '',
            Cast::bool($row['enabled'] ?? null) ?? false,
            Cast::float($row['cost_per_licence_per_month'] ?? null) ?? 0.0,
            Cast::int($row['minimum_licence_count'] ?? null) ?? 0,
            Cast::int($row['maximum_licence_count'] ?? null) ?? 0,
            max(1, Cast::int($row['licence_step_count'] ?? null) ?? 1),
            Cast::string($row['group'] ?? null),
            Cast::strings($row['supported_operating_systems'] ?? null),
            $row,
        );
    }

    /**
     * Whether this many licences may be bought - all three rules at once.
     */
    public function allowsCount(int $count): bool
    {
        return $count >= $this->minimumLicenceCount
            && $count <= $this->maximumLicenceCount
            && $count % $this->licenceStepCount === 0;
    }

    /**
     * The nearest acceptable licence count at or above the one asked for, or null when even
     * the maximum will not satisfy the step.
     */
    public function roundUpCount(int $count): ?int
    {
        $count = max($count, $this->minimumLicenceCount);

        if ($count % $this->licenceStepCount !== 0) {
            $count += $this->licenceStepCount - ($count % $this->licenceStepCount);
        }

        return $count <= $this->maximumLicenceCount ? $count : null;
    }

    /**
     * Whether this software can be installed on an image, by slug.
     *
     * False for a backup image, which has no slug to match - see the class note.
     */
    public function supportsImage(?string $imageSlug): bool
    {
        return $imageSlug !== null && in_array($imageSlug, $this->supportedOperatingSystems, true);
    }

    /**
     * Whether two products are mutually exclusive, which is what sharing a group means.
     */
    public function conflictsWith(self $other): bool
    {
        return $this->group !== null && $this->group === $other->group && $this->id !== $other->id;
    }

    /**
     * What this many licences cost per month, in AU$.
     */
    public function monthlyCost(int $count): float
    {
        return $this->costPerLicencePerMonth * max(0, $count);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'cost_per_licence_per_month' => $this->costPerLicencePerMonth,
            'minimum_licence_count' => $this->minimumLicenceCount,
            'maximum_licence_count' => $this->maximumLicenceCount,
            'licence_step_count' => $this->licenceStepCount,
            'group' => $this->group,
            'supported_operating_systems' => $this->supportedOperatingSystems,
        ];
    }
}
