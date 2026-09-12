<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\AdvancedFeature;
use Hampel\BinaryLane\Api\Enum\VmMachineType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What advanced features THIS server can be given.
 *
 * PER SERVER, NOT PER ACCOUNT. The available set depends on the host the server is on and on
 * its image, so a feature that worked on one server is not necessarily offered on another.
 * Asking for one that is not in this list is a 400.
 *
 * A value the API sends that this package has no case for is kept as a string rather than
 * dropped - `unknownFeatures` and `unknownMachineTypes` - because a feature list that
 * silently omits a newly added option looks like a server that cannot have it.
 */
final class AvailableAdvancedServerFeatures implements \JsonSerializable
{
    /**
     * @param  list<ProcessorModel>  $processorModels
     * @param  list<VmMachineType>  $machineTypes
     * @param  list<AdvancedFeature>  $advancedFeatures
     * @param  list<string>  $unknownMachineTypes
     * @param  list<string>  $unknownFeatures
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $processorModels = [],
        public readonly array $machineTypes = [],
        public readonly array $advancedFeatures = [],
        public readonly array $unknownMachineTypes = [],
        public readonly array $unknownFeatures = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $machineTypes = [];
        $unknownMachineTypes = [];

        foreach (Cast::strings($row['machine_types'] ?? null) as $value) {
            $type = VmMachineType::tryFrom($value);

            if ($type !== null) {
                $machineTypes[] = $type;
            } else {
                $unknownMachineTypes[] = $value;
            }
        }

        $features = [];
        $unknownFeatures = [];

        foreach (Cast::strings($row['advanced_features'] ?? null) as $value) {
            $feature = AdvancedFeature::tryFrom($value);

            if ($feature !== null) {
                $features[] = $feature;
            } else {
                $unknownFeatures[] = $value;
            }
        }

        return new self(
            Cast::objects($row['processor_models'] ?? null, ProcessorModel::fromArray(...)),
            $machineTypes,
            $features,
            $unknownMachineTypes,
            $unknownFeatures,
            $row,
        );
    }

    public function offers(AdvancedFeature $feature): bool
    {
        return in_array($feature, $this->advancedFeatures, true);
    }

    public function offersMachineType(VmMachineType $type): bool
    {
        return in_array($type, $this->machineTypes, true);
    }

    public function processorModel(int $id): ?ProcessorModel
    {
        foreach ($this->processorModels as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The offered features that can actually be turned on, read-only ones excluded.
     *
     * @return list<AdvancedFeature>
     */
    public function settableFeatures(): array
    {
        return array_values(array_filter(
            $this->advancedFeatures,
            static fn (AdvancedFeature $feature): bool => !$feature->isReadOnly()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'processor_models' => $this->processorModels,
            'machine_types' => array_map(static fn (VmMachineType $t): string => $t->value, $this->machineTypes),
            'advanced_features' => array_map(static fn (AdvancedFeature $f): string => $f->value, $this->advancedFeatures),
        ];
    }
}
