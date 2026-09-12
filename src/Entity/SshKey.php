<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * A public key held on the account, for deploying to new servers.
 *
 * `default` IS THE FIELD WITH REACH. A key marked default is deployed to EVERY new server
 * created without an explicit `ssh_keys` list - so marking one changes what happens on every
 * future create that does not say otherwise, including ones made from the control panel.
 * That is the mechanism behind the null-versus-empty-array rule on Request\CreateServer.
 *
 * BOTH THE ID AND THE FINGERPRINT ADDRESS A KEY. `GET /v2/account/keys/{key_id}` takes
 * either, which is what makes it possible to look a key up from an `authorized_keys` file
 * without storing an id.
 */
final class SshKey implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly string $fingerprint = '',
        public readonly string $publicKey = '',
        public readonly ?string $name = null,
        public readonly bool $default = false,
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
            Cast::string($row['fingerprint'] ?? null) ?? '',
            Cast::string($row['public_key'] ?? null) ?? '',
            Cast::string($row['name'] ?? null),
            Cast::bool($row['default'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * Whether this key goes onto every new server that does not name its own keys.
     */
    public function isDeployedByDefault(): bool
    {
        return $this->default;
    }

    /**
     * The key's algorithm - `ssh-ed25519`, `ssh-rsa` - read off the key itself.
     */
    public function algorithm(): ?string
    {
        $parts = preg_split('/\s+/', trim($this->publicKey));

        return is_array($parts) && isset($parts[0]) && $parts[0] !== '' ? $parts[0] : null;
    }

    /**
     * The comment at the end of an OpenSSH public key - usually `user@host`.
     */
    public function comment(): ?string
    {
        $parts = preg_split('/\s+/', trim($this->publicKey), 3);

        return is_array($parts) && isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
    }

    /**
     * What to pass where the API takes an id or a fingerprint.
     */
    public function reference(): string
    {
        return $this->id > 0 ? (string) $this->id : $this->fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'public_key' => $this->publicKey,
            'name' => $this->name,
            'default' => $this->default,
        ];
    }
}
