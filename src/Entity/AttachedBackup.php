<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A backup image currently mounted on a server, so its contents can be read from inside.
 *
 * IT DETACHES ITSELF. `attachmentExpires` is the time BinaryLane will unmount it unless
 * something extends the attachment, so a recovery process that takes longer than expected
 * loses its disk mid-way. expiresWithin() is the check worth making before starting a long
 * copy.
 *
 * `diskIdentifiers` are the operating-system-level names of the attached disks - what to
 * mount once inside the server.
 */
final class AttachedBackup implements \JsonSerializable
{
    /**
     * @param  list<string>  $diskIdentifiers
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly array $diskIdentifiers = [],
        public readonly ?\DateTimeImmutable $attachedAt = null,
        public readonly ?\DateTimeImmutable $attachmentExpires = null,
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
            Cast::strings($row['disk_identifiers'] ?? null),
            Cast::datetime($row['attached_at'] ?? null),
            Cast::datetime($row['attachment_expires'] ?? null),
            $row,
        );
    }

    /**
     * Whether the attachment will end within the given number of seconds.
     *
     * False when no expiry was given, which means "nothing says it is about to go" rather
     * than "it will stay forever".
     */
    public function expiresWithin(int $seconds, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->attachmentExpires === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->attachmentExpires <= $now->modify('+' . $seconds . ' seconds');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'disk_identifiers' => $this->diskIdentifiers,
            'attached_at' => $this->attachedAt?->format(\DateTimeInterface::ATOM),
            'attachment_expires' => $this->attachmentExpires?->format(\DateTimeInterface::ATOM),
        ];
    }
}
