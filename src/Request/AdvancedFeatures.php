<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\AvailableAdvancedServerFeatures;
use Hampel\BinaryLane\Api\Enum\AdvancedFeature;
use Hampel\BinaryLane\Api\Enum\VideoDevice;
use Hampel\BinaryLane\Api\Enum\VmMachineType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * A change to a server's virtualisation options - the specification's
 * `ChangeAdvancedFeatures`.
 *
 * THE FEATURE LIST IS A REPLACEMENT, AND NULL, EMPTY AND ABSENT ARE THREE DIFFERENT THINGS:
 *
 *  - field absent: keep whatever the server has now;
 *  - empty array: DISABLE EVERY advanced feature;
 *  - a list: end up with exactly those.
 *
 * So turning one feature on means sending the ones already on plus the new one - which is
 * what Entity\AdvancedServerFeatures::with() produces, read-only features already removed.
 * `withFeatures([$one])` turns everything else off.
 *
 * AUTOMATIC AND EXPLICIT CANNOT BOTH BE SENT. The specification says `automatic_processor_model`
 * and `processor_model` must not be provided together, and the same for the machine type.
 * That is enforced here, rather than as a 400 that names one field and not the conflict.
 */
final class AdvancedFeatures implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private readonly array $payload = [],
    ) {
    }

    /**
     * Change nothing yet.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * End up with exactly these features enabled.
     *
     * EVERYTHING NOT IN THE LIST IS TURNED OFF. Start from
     * `$server->advancedFeatures->with($feature)` to add one without losing the others.
     *
     * A read-only feature in the list is refused rather than sent: it cannot be enabled, and
     * including it usually means a fetched set was echoed back without filtering.
     *
     * @param  list<AdvancedFeature>  $features
     */
    public function withFeatures(array $features): self
    {
        foreach ($features as $feature) {
            if ($feature->isReadOnly()) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" reports what the server\'s image supports and cannot be enabled. Use '
                        . 'Entity\AdvancedServerFeatures::settableFeatures() to drop the read-only ones.',
                    $feature->value
                ));
            }
        }

        return $this->with('enabled_advanced_features', array_values(array_map(
            static fn (AdvancedFeature $feature): string => $feature->value,
            $features
        )));
    }

    /**
     * Turn every advanced feature off - an empty array, which is not the same as omitting the
     * field.
     */
    public function withoutFeatures(): self
    {
        return $this->with('enabled_advanced_features', []);
    }

    /**
     * Pin the server to one CPU model. Narrows which hosts it can run on.
     */
    public function withProcessorModel(int $id): self
    {
        if (isset($this->payload['automatic_processor_model'])) {
            throw new InvalidArgumentException(
                'A processor model and automatic processor model selection cannot both be sent; '
                    . 'the specification forbids it. Choose one.'
            );
        }

        return $this->with('processor_model', $id);
    }

    /**
     * Let BinaryLane choose the best available CPU model - the unconstrained default.
     */
    public function withAutomaticProcessorModel(): self
    {
        if (isset($this->payload['processor_model'])) {
            throw new InvalidArgumentException(
                'A processor model and automatic processor model selection cannot both be sent; '
                    . 'the specification forbids it. Choose one.'
            );
        }

        return $this->with('automatic_processor_model', true);
    }

    /**
     * Pin the QEMU machine type. Changes the virtual hardware the guest sees.
     */
    public function withMachineType(VmMachineType $type): self
    {
        if (isset($this->payload['automatic_machine_type'])) {
            throw new InvalidArgumentException(
                'A machine type and automatic machine type selection cannot both be sent; '
                    . 'the specification forbids it. Choose one.'
            );
        }

        return $this->with('machine_type', $type->value);
    }

    /**
     * Let BinaryLane choose the machine type.
     */
    public function withAutomaticMachineType(): self
    {
        if (isset($this->payload['machine_type'])) {
            throw new InvalidArgumentException(
                'A machine type and automatic machine type selection cannot both be sent; '
                    . 'the specification forbids it. Choose one.'
            );
        }

        return $this->with('automatic_machine_type', true);
    }

    public function withVideoDevice(VideoDevice $device): self
    {
        return $this->with('video_device', $device->value);
    }

    /**
     * Check the request against what this particular server is actually offered.
     *
     * The available set is per server rather than per account - see
     * Endpoint\Servers::availableAdvancedFeatures() - so a feature that worked elsewhere is
     * not necessarily on offer here.
     */
    public function validateAgainst(AvailableAdvancedServerFeatures $available): self
    {
        $requested = $this->payload['enabled_advanced_features'] ?? [];

        foreach (is_array($requested) ? $requested : [] as $value) {
            $feature = is_string($value) ? AdvancedFeature::tryFrom($value) : null;

            if ($feature === null || !$available->offers($feature)) {
                throw new InvalidArgumentException(sprintf(
                    'This server is not offered the advanced feature "%s".',
                    is_string($value) ? $value : 'unknown'
                ));
            }
        }

        $machineType = $this->payload['machine_type'] ?? null;

        if (is_string($machineType)) {
            $type = VmMachineType::tryFrom($machineType);

            if ($type === null || !$available->offersMachineType($type)) {
                throw new InvalidArgumentException(sprintf(
                    'This server is not offered the machine type "%s".',
                    $machineType
                ));
            }
        }

        $processorModel = $this->payload['processor_model'] ?? null;

        if (is_int($processorModel) && $available->processorModel($processorModel) === null) {
            throw new InvalidArgumentException(sprintf(
                'This server is not offered processor model %d.',
                $processorModel
            ));
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->payload === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    private function with(string $key, mixed $value): self
    {
        return new self([...$this->payload, $key => $value]);
    }
}
