<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\AdvancedFeature;
use Hampel\BinaryLane\Api\Enum\VideoDevice;
use Hampel\BinaryLane\Api\Enum\VmMachineType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The virtualisation options currently in effect for a server.
 *
 * THE CHANGE ACTION REPLACES THIS WHOLE SET rather than merging into it, so the safe way to
 * turn one feature on is to read this, add to it, and send the result - which is what
 * `settableFeatures()` is for. Sending only the feature you wanted turns every other one off.
 *
 * AND THREE OF THEM CANNOT BE SENT BACK. `cloud-init`, `qemu-guest-agent` and `uefi-boot`
 * report what the image supports rather than what is configured, so echoing a fetched set
 * straight back includes fields the change action will not act on. settableFeatures() drops
 * them; see AdvancedFeature::isReadOnly().
 *
 * A null `processorModel` or `machineType` means BinaryLane chooses, which is what nearly
 * everything should leave them as - pinning either narrows the hosts the server can run on.
 */
final class AdvancedServerFeatures implements \JsonSerializable
{
    /**
     * @param  list<AdvancedFeature>  $enabledAdvancedFeatures
     * @param  list<string>  $unknownFeatures  values the API sent that this package has no
     *                                         case for, kept rather than dropped
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $enabledAdvancedFeatures = [],
        public readonly ?VideoDevice $videoDevice = null,
        public readonly ?int $processorModel = null,
        public readonly ?VmMachineType $machineType = null,
        public readonly array $unknownFeatures = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $features = [];
        $unknown = [];

        foreach (Cast::strings($row['enabled_advanced_features'] ?? null) as $value) {
            $feature = AdvancedFeature::tryFrom($value);

            if ($feature !== null) {
                $features[] = $feature;
            } else {
                $unknown[] = $value;
            }
        }

        return new self(
            $features,
            VideoDevice::tryFrom(Cast::string($row['video_device'] ?? null) ?? ''),
            Cast::int($row['processor_model'] ?? null),
            VmMachineType::tryFrom(Cast::string($row['machine_type'] ?? null) ?? ''),
            $unknown,
            $row,
        );
    }

    public function has(AdvancedFeature $feature): bool
    {
        return in_array($feature, $this->enabledAdvancedFeatures, true);
    }

    /**
     * The enabled features that the change action will actually act on.
     *
     * This is the list to start from when turning a feature on or off, because the change
     * action replaces rather than merges and the read-only features are not settable.
     *
     * @return list<AdvancedFeature>
     */
    public function settableFeatures(): array
    {
        return array_values(array_filter(
            $this->enabledAdvancedFeatures,
            static fn (AdvancedFeature $feature): bool => !$feature->isReadOnly()
        ));
    }

    /**
     * The settable set with one feature added - the safe way to turn something on.
     *
     * @return list<AdvancedFeature>
     */
    public function with(AdvancedFeature $feature): array
    {
        $features = $this->settableFeatures();

        if (!in_array($feature, $features, true)) {
            $features[] = $feature;
        }

        return $features;
    }

    /**
     * The settable set with one feature removed.
     *
     * @return list<AdvancedFeature>
     */
    public function without(AdvancedFeature $feature): array
    {
        return array_values(array_filter(
            $this->settableFeatures(),
            static fn (AdvancedFeature $enabled): bool => $enabled !== $feature
        ));
    }

    /**
     * Whether BinaryLane is choosing the CPU model and the machine type, which is the
     * unconstrained default.
     */
    public function usesDefaultHardware(): bool
    {
        return $this->processorModel === null && $this->machineType === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'enabled_advanced_features' => array_map(
                static fn (AdvancedFeature $feature): string => $feature->value,
                $this->enabledAdvancedFeatures
            ),
            'video_device' => $this->videoDevice?->value,
            'processor_model' => $this->processorModel,
            'machine_type' => $this->machineType?->value,
        ];
    }
}
