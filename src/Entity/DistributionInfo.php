<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\DistributionFeature;
use Hampel\BinaryLane\Api\Enum\PasswordRecoveryType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What an image's operating system supports.
 *
 * READ THIS BEFORE BUILDING A CREATE REQUEST. `features` says whether the initial install
 * accepts SSH keys, RDP, or cloud-init user-data; sending any of those to an image that does
 * not advertise it is accepted by the API and then quietly does nothing. supportsSshKeys()
 * and supportsUserData() are the two checks worth making.
 *
 * `passwordRecovery` DECIDES WHETHER A PASSWORD RESET REBOOTS THE SERVER. On a production
 * server that is the difference between a maintenance window and a phone call - see
 * PasswordRecoveryType.
 */
final class DistributionInfo implements \JsonSerializable
{
    /**
     * @param  list<DistributionFeature>  $features
     * @param  list<string>  $unknownFeatures  values this package has no case for, kept
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $imageId = 0,
        public readonly ?PasswordRecoveryType $passwordRecovery = null,
        public readonly ?string $remoteAccessUser = null,
        public readonly array $features = [],
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

        foreach (Cast::strings($row['features'] ?? null) as $value) {
            $feature = DistributionFeature::tryFrom($value);

            if ($feature !== null) {
                $features[] = $feature;
            } else {
                $unknown[] = $value;
            }
        }

        return new self(
            Cast::int($row['image_id'] ?? null) ?? 0,
            PasswordRecoveryType::tryFrom(Cast::string($row['password_recovery'] ?? null) ?? ''),
            Cast::string($row['remote_access_user'] ?? null),
            $features,
            $unknown,
            $row,
        );
    }

    public function supports(DistributionFeature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * Whether `ssh_keys` on a create request will do anything.
     */
    public function supportsSshKeys(): bool
    {
        return $this->supports(DistributionFeature::Ssh);
    }

    /**
     * Whether `user_data` on a create request will be read by the installed system.
     */
    public function supportsUserData(): bool
    {
        return $this->supports(DistributionFeature::UserData);
    }

    public function supportsRemoteDesktop(): bool
    {
        return $this->supports(DistributionFeature::RemoteDesktop);
    }

    /**
     * Whether a password reset through the API will restart the server.
     *
     * True when nothing is known, which is the conservative way round: assuming no reboot and
     * being wrong is worse than the reverse.
     */
    public function passwordResetRequiresRestart(): bool
    {
        return $this->passwordRecovery?->requiresRestart() ?? true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'image_id' => $this->imageId,
            'password_recovery' => $this->passwordRecovery?->value,
            'remote_access_user' => $this->remoteAccessUser,
            'features' => array_map(
                static fn (DistributionFeature $feature): string => $feature->value,
                $this->features
            ),
        ];
    }
}
