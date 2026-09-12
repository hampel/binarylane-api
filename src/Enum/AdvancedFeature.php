<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * A per-server virtualisation option.
 *
 * THREE OF THESE ARE READ-ONLY and the specification marks them so in prose rather than in
 * the schema: `cloud-init`, `qemu-guest-agent` and `uefi-boot` report what the server's image
 * supports, and sending them to ChangeAdvancedFeatures does not turn them on. isReadOnly()
 * is that list, so a caller can filter a fetched set before echoing it back - which is the
 * shape of the mistake, since the change action REPLACES the whole set rather than merging.
 *
 * The available set differs per server. Servers::availableAdvancedFeatures() answers what
 * this particular one accepts.
 */
enum AdvancedFeature: string
{
    /** HyperV support. On by default for Windows, generally pointless elsewhere. */
    case EmulatedHyperv = 'emulated-hyperv';

    /** Replace VirtIO disk and network devices with emulated IDE and Intel E1000 hardware. Much slower. */
    case EmulatedDevices = 'emulated-devices';

    /** Run KVM guests inside the server. Only useful with a VPC, since the networking limits still apply. */
    case NestedVirt = 'nested-virt';

    /** Attach `virtio-win.iso` as a virtual CD, for installing Windows yourself. */
    case DriverDisk = 'driver-disk';

    /** Hide the per-server BIOS UUID. Some licensed software ties a licence to it. */
    case UnsetUuid = 'unset-uuid';

    /** Present the host node's local time to the BIOS rather than UTC. For your own Windows installs. */
    case LocalRtc = 'local-rtc';

    /** An emulated TPM v1.2 device. The TPM state is NOT backed up. */
    case EmulatedTpm = 'emulated-tpm';

    /** Read-only. The image provides a cloud-init datasource. */
    case CloudInit = 'cloud-init';

    /** Read-only. The QEMU guest agent can reset the password without a reboot. */
    case QemuGuestAgent = 'qemu-guest-agent';

    /** Read-only. The server boots UEFI rather than legacy BIOS. */
    case UefiBoot = 'uefi-boot';

    /**
     * Whether this feature reports a capability rather than setting one.
     *
     * A read-only feature can appear in a server's enabled set and cannot be put there by
     * asking. Sending one is not an error; it just has no effect.
     */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::CloudInit, self::QemuGuestAgent, self::UefiBoot => true,
            default => false,
        };
    }

    /**
     * The features that can actually be changed.
     *
     * @return list<self>
     */
    public static function settable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $f): bool => !$f->isReadOnly()));
    }

    /**
     * BinaryLane's own description of what this does.
     */
    public function description(): string
    {
        return match ($this) {
            self::EmulatedHyperv => 'Enable HyperV support. Enabled by default on Windows servers, generally of no value for non-Windows servers.',
            self::EmulatedDevices => 'Replace the KVM VirtIO disk and network devices with emulated versions of physical hardware: an old IDE HDD and an Intel E1000 network card. Much slower than VirtIO.',
            self::NestedVirt => 'Enable the functionality necessary to run your own KVM servers within your server. The networking limits still apply, so this is generally only useful with a Virtual Private Cloud.',
            self::DriverDisk => 'Attach a copy of the KVM driver disc for Windows ("virtio-win.iso") as a virtual CD.',
            self::UnsetUuid => 'Stop the virtual BIOS exposing a per-server 128-bit unique identifier. Some proprietary licensed software ties a licence to it.',
            self::LocalRtc => 'Present the host node\'s local timezone to the virtual BIOS rather than UTC. Needed for your own Windows installations.',
            self::EmulatedTpm => 'Provide an emulated TPM v1.2 device. Warning: the TPM state is not backed up.',
            self::CloudInit => 'Read-only. The server is provided a datasource for the cloud-init service.',
            self::QemuGuestAgent => 'Read-only. The server allows QEMU Guest Agent to perform a password reset without rebooting.',
            self::UefiBoot => 'Read-only. The server uses UEFI instead of legacy PC BIOS.',
        };
    }
}
