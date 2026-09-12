<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * URLs for the out-of-band console, and how long they last.
 *
 * THESE URLS EXPIRE AND THEY ARE CREDENTIALS. Anyone holding one has console access to the
 * server until `expiry`, without a password - so they must not be logged, stored, or put in a
 * URL that gets written down anywhere. Fetch one when it is needed rather than keeping it.
 *
 * `iframe` embeds; `browser` is the full-screen version. `width` and `height` are the
 * console's native resolution, for sizing the frame so it is not scaled.
 */
final class Console implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $iframe = '',
        public readonly string $browser = '',
        public readonly int $width = 0,
        public readonly int $height = 0,
        public readonly ?\DateTimeImmutable $expiry = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['iframe'] ?? null) ?? '',
            Cast::string($row['browser'] ?? null) ?? '',
            Cast::int($row['width'] ?? null) ?? 0,
            Cast::int($row['height'] ?? null) ?? 0,
            Cast::datetime($row['expiry'] ?? null),
            $row,
        );
    }

    /**
     * Whether the URLs still work.
     */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        if ($this->expiry === null) {
            return false;
        }

        return $this->expiry <= ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Seconds until the URLs stop working, or null when no expiry was given.
     */
    public function expiresIn(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->expiry === null) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $this->expiry->getTimestamp() - $now->getTimestamp());
    }

    /**
     * The URLs are omitted, deliberately: they are credentials, and this is what gets logged.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['console' => sprintf(
            'console URLs for a %dx%d session, expiring %s (withheld)',
            $this->width,
            $this->height,
            $this->expiry?->format(\DateTimeInterface::ATOM) ?? 'at an unstated time'
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'iframe' => $this->iframe,
            'browser' => $this->browser,
            'width' => $this->width,
            'height' => $this->height,
            'expiry' => $this->expiry?->format(\DateTimeInterface::ATOM),
        ];
    }
}
