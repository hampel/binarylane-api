<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\ServerStatus;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A cloud server.
 *
 * THE SIZE IS NOT THE SERVER. `size` is the catalogue entry the server was created from;
 * `selectedSizeOptions` is what this particular server actually has, after add-ons. The two
 * disagree on any server that has ever been given extra memory, disk, transfer or IPv4
 * addresses - and the top-level `memory`, `vcpus` and `disk` fields here follow the SERVER,
 * so they are the ones to read.
 *
 * `status` HAS TWO WAYS OF BEING OFF. `off` is a server you can power back on; `archive` is
 * one powered off for cancellation or non-payment, which needs Uncancel first. See
 * ServerStatus, which also records the wire value that YAML parsers get wrong.
 *
 * `isUnderMaintenance` IS THE FIELD THAT EXPLAINS OTHERWISE INEXPLICABLE REFUSALS - the
 * specification says most actions are unavailable while it is true. Check it before
 * concluding that a failing power action means something is wrong.
 *
 * `passwordChangeSupported` DECIDES WHETHER PasswordReset IS EVEN POSSIBLE, and the image's
 * DistributionInfo::$passwordRecovery decides whether it reboots. Two different questions,
 * asked in two different places.
 *
 * `permalink` is a stable two-word identifier assigned at creation. Unlike the hostname it
 * cannot be changed, which makes it the thing to key on.
 */
final class Server implements \JsonSerializable
{
    /**
     * @param  string  $name  the HOSTNAME. Changeable with the Rename action, so not an
     *                        identifier - see `permalink` and `id`
     * @param  int  $memory  MB, for this server rather than for its size
     * @param  int  $disk  GB, likewise
     * @param  list<int>  $backupIds  the image ids of this server's existing backups
     * @param  list<string>  $features
     * @param  list<Disk>  $disks
     * @param  list<string>  $failoverIps
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name = '',
        public readonly int $memory = 0,
        public readonly int $vcpus = 0,
        public readonly int $disk = 0,
        public readonly ?int $vpcId = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?ServerStatus $status = null,
        public readonly array $backupIds = [],
        public readonly array $features = [],
        public readonly ?Region $region = null,
        public readonly ?Image $image = null,
        public readonly ?Size $size = null,
        public readonly string $sizeSlug = '',
        public readonly ?SelectedSizeOptions $selectedSizeOptions = null,
        public readonly ?Networks $networks = null,
        public readonly ?Kernel $kernel = null,
        public readonly ?BackupWindow $nextBackupWindow = null,
        public readonly array $disks = [],
        public readonly ?BackupSettings $backupSettings = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly array $failoverIps = [],
        public readonly ?Host $host = null,
        public readonly ?int $partnerId = null,
        public readonly bool $passwordChangeSupported = false,
        public readonly ?string $permalink = null,
        public readonly ?AttachedBackup $attachedBackup = null,
        public readonly ?AdvancedServerFeatures $advancedFeatures = null,
        public readonly bool $isUnderMaintenance = false,
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
            Cast::int($row['memory'] ?? null) ?? 0,
            Cast::int($row['vcpus'] ?? null) ?? 0,
            Cast::int($row['disk'] ?? null) ?? 0,
            Cast::int($row['vpc_id'] ?? null),
            Cast::datetime($row['created_at'] ?? null),
            ServerStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::ints($row['backup_ids'] ?? null),
            Cast::strings($row['features'] ?? null),
            Cast::nested($row['region'] ?? null, Region::fromArray(...)),
            Cast::nested($row['image'] ?? null, Image::fromArray(...)),
            Cast::nested($row['size'] ?? null, Size::fromArray(...)),
            Cast::string($row['size_slug'] ?? null) ?? '',
            Cast::nested($row['selected_size_options'] ?? null, SelectedSizeOptions::fromArray(...)),
            Cast::nested($row['networks'] ?? null, Networks::fromArray(...)),
            Cast::nested($row['kernel'] ?? null, Kernel::fromArray(...)),
            Cast::nested($row['next_backup_window'] ?? null, BackupWindow::fromArray(...)),
            Cast::objects($row['disks'] ?? null, Disk::fromArray(...)),
            Cast::nested($row['backup_settings'] ?? null, BackupSettings::fromArray(...)),
            Cast::datetime($row['cancelled_at'] ?? null),
            Cast::strings($row['failover_ips'] ?? null),
            Cast::nested($row['host'] ?? null, Host::fromArray(...)),
            Cast::int($row['partner_id'] ?? null),
            Cast::bool($row['password_change_supported'] ?? null) ?? false,
            Cast::string($row['permalink'] ?? null),
            Cast::nested($row['attached_backup'] ?? null, AttachedBackup::fromArray(...)),
            Cast::nested($row['advanced_features'] ?? null, AdvancedServerFeatures::fromArray(...)),
            Cast::bool($row['is_under_maintenance'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * Whether the server is powered on and usable.
     */
    public function isRunning(): bool
    {
        return $this->status?->isRunning() ?? false;
    }

    /**
     * Whether the server is powered off, either because it was turned off or because it was
     * cancelled. isCancelled() is how to tell those apart.
     */
    public function isOff(): bool
    {
        return $this->status?->isOff() ?? false;
    }

    /**
     * Whether the server has been cancelled.
     *
     * Answered from `cancelledAt` as well as the status, because the two arrive at different
     * times: a server cancelled with a future effective date has the timestamp before it has
     * the `archive` status.
     */
    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null || $this->status === ServerStatus::Archive;
    }

    /**
     * Whether a power-on could work right now. False for a cancelled server, which needs
     * Uncancel first, and for one still being built.
     */
    public function canPowerOn(): bool
    {
        return ($this->status?->canPowerOn() ?? false) && !$this->isUnderMaintenance;
    }

    /**
     * Whether the server is in a state that will refuse most actions.
     *
     * The single check worth making before a scripted action, because the two conditions
     * produce the same unhelpful 400 and neither is the caller's mistake.
     */
    public function isActionable(): bool
    {
        return !$this->isUnderMaintenance && $this->status !== ServerStatus::New;
    }

    /**
     * Whether the server sits in a virtual private cloud.
     */
    public function isInVpc(): bool
    {
        return $this->vpcId !== null;
    }

    /**
     * The address to connect to, or null when the server has none.
     *
     * A server can legitimately have no public address at all - there is a discount for
     * declining IPv4, and a VPC-only server may have nothing routable.
     */
    public function publicAddress(): ?string
    {
        return $this->networks?->primaryPublicAddress()?->ipAddress;
    }

    /**
     * Every private address the server has, as strings.
     *
     * @return list<string>
     */
    public function privateAddresses(): array
    {
        return array_map(
            static fn (Network $network): string => $network->ipAddress,
            $this->networks?->privateV4() ?? []
        );
    }

    /**
     * The primary disk, which the size governs and the disk actions may not touch.
     */
    public function primaryDisk(): ?Disk
    {
        foreach ($this->disks as $disk) {
            if ($disk->primary) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * The disks that ResizeDisk and DeleteDisk can act on.
     *
     * @return list<Disk>
     */
    public function additionalDisks(): array
    {
        return array_values(array_filter($this->disks, static fn (Disk $disk): bool => $disk->isAdditional()));
    }

    /**
     * Whether scheduled backups are on.
     *
     * Read from the SELECTED OPTIONS rather than from the backup settings, because the
     * settings say when backups would run and the options say whether there are any slots for
     * them to run into. A server with a backup schedule and no slots takes no backups.
     */
    public function hasBackups(): bool
    {
        return $this->selectedSizeOptions?->hasBackups() ?? false;
    }

    /**
     * Whether a backup image is currently mounted on the server - and therefore whether
     * something is mid-recovery.
     */
    public function hasAttachedBackup(): bool
    {
        return $this->attachedBackup !== null;
    }

    /**
     * Whether a password reset through the API is possible for this server at all.
     *
     * Whether it will REBOOT the server is a different question, answered by the image - see
     * DistributionInfo::passwordResetRequiresRestart().
     */
    public function supportsPasswordReset(): bool
    {
        return $this->passwordChangeSupported;
    }

    /**
     * Whether the server advertises a named feature.
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * The region's slug, from whichever of the two places carries it.
     */
    public function regionSlug(): ?string
    {
        return $this->region->slug ?? Cast::string($this->raw['region_slug'] ?? null);
    }

    /**
     * A single line identifying the server, for a log or a listing.
     */
    public function describe(): string
    {
        return sprintf(
            '#%d %s (%s, %s)',
            $this->id,
            $this->name !== '' ? $this->name : ($this->permalink ?? 'unnamed'),
            $this->sizeSlug !== '' ? $this->sizeSlug : 'unknown size',
            $this->status->value ?? 'unknown status'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'name' => $this->name,
            'memory' => $this->memory,
            'vcpus' => $this->vcpus,
            'disk' => $this->disk,
            'vpc_id' => $this->vpcId,
            'created_at' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'status' => $this->status?->value,
            'backup_ids' => $this->backupIds,
            'features' => $this->features,
            'region' => $this->region,
            'image' => $this->image,
            'size' => $this->size,
            'size_slug' => $this->sizeSlug,
            'selected_size_options' => $this->selectedSizeOptions,
            'networks' => $this->networks,
            'kernel' => $this->kernel,
            'next_backup_window' => $this->nextBackupWindow,
            'disks' => $this->disks,
            'backup_settings' => $this->backupSettings,
            'cancelled_at' => $this->cancelledAt?->format(\DateTimeInterface::ATOM),
            'failover_ips' => $this->failoverIps,
            'host' => $this->host,
            'partner_id' => $this->partnerId,
            'password_change_supported' => $this->passwordChangeSupported,
            'permalink' => $this->permalink,
            'attached_backup' => $this->attachedBackup,
            'advanced_features' => $this->advancedFeatures,
            'is_under_maintenance' => $this->isUnderMaintenance,
        ];
    }
}
