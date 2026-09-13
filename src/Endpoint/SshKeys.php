<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\SshKey;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * SSH keys held on the account.
 *
 * `/v2/account/keys` - the specification files these under their own tag, `Keys`, rather than
 * with the account.
 *
 * A KEY IS ADDRESSED BY ID OR BY FINGERPRINT, which the API treats interchangeably. That is
 * what makes it possible to check whether a key from an `authorized_keys` file is already
 * here without storing an id.
 *
 * MARKING A KEY DEFAULT CHANGES EVERY FUTURE SERVER. A default key is deployed to every
 * server created without an explicit key list - including ones created from the control panel
 * and by other people on the account. It is the widest-reaching setting in this endpoint and
 * it looks like a convenience.
 */
final class SshKeys extends Endpoint
{
    public const COLLECTION = 'ssh_keys';

    /**
     * One key by id or fingerprint. Raises NotFoundException when there is no such key.
     */
    public function get(int|string $key): SshKey
    {
        return $this->apiObject($this->path($key), 'ssh_key', SshKey::fromArray(...));
    }

    /**
     * One key by id or fingerprint, or null.
     */
    public function find(int|string $key): ?SshKey
    {
        return $this->apiFind($this->path($key), 'ssh_key', SshKey::fromArray(...));
    }

    /**
     * Whether the account already holds a key with this fingerprint.
     */
    public function exists(int|string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * One page of the account's keys.
     *
     * @return Page<SshKey>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('account/keys', self::COLLECTION, SshKey::fromArray(...), $page, $perPage);
    }

    /**
     * Every key, a page at a time.
     *
     * @return \Generator<int, SshKey>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('account/keys', self::COLLECTION, SshKey::fromArray(...), $perPage);
    }

    /**
     * Every key, as a list. An account's key set is small enough to hold.
     *
     * @return list<SshKey>
     */
    public function all(?int $perPage = null): array
    {
        return iterator_to_array($this->each($perPage), false);
    }

    public function count(): int
    {
        return $this->apiCount('account/keys', self::COLLECTION);
    }

    /**
     * The keys that will be deployed to a new server that does not name its own.
     *
     * @return list<SshKey>
     */
    public function defaults(): array
    {
        return array_values(array_filter($this->all(), static fn (SshKey $key): bool => $key->default));
    }

    /**
     * Add a key.
     *
     * `$public_key` IS THE `authorized_keys` LINE, algorithm and all - `ssh-ed25519 AAAA...
     * user@host`, not just the base64 body.
     *
     * `$default` deploys it to every future server created without an explicit key list.
     */
    public function create(string $publicKey, string $name, bool $default = false): SshKey
    {
        $publicKey = trim($publicKey);
        $name = trim($name);

        if ($publicKey === '') {
            throw new InvalidArgumentException('An SSH key needs a public key.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('An SSH key needs a name to identify it by.');
        }

        return SshKey::fromArray(
            $this->apiPost('account/keys', [
                'public_key' => $publicKey,
                'name' => $name,
                'default' => $default,
            ])->requireObject('ssh_key')
        );
    }

    /**
     * Rename a key, and optionally change whether it is a default.
     *
     * THE NAME IS REQUIRED EVEN WHEN ONLY THE DEFAULT FLAG IS CHANGING - the specification
     * marks it so. `$default` left null keeps the key's current status.
     *
     * THE PUBLIC KEY CANNOT BE CHANGED. There is no field for it: replacing a key means
     * adding the new one and deleting the old.
     */
    public function update(int|string $key, string $name, ?bool $default = null): SshKey
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'Updating an SSH key requires its name, even when only the default flag is changing.'
            );
        }

        $payload = ['name' => $name];

        if ($default !== null) {
            $payload['default'] = $default;
        }

        return SshKey::fromArray($this->apiPut($this->path($key), $payload)->requireObject('ssh_key'));
    }

    /**
     * Delete a key.
     *
     * REMOVES IT FROM THE ACCOUNT, NOT FROM ANY SERVER. Keys already deployed stay in the
     * servers' `authorized_keys` files and keep working; this only stops it being deployed
     * again. Revoking access means editing the servers.
     *
     * Answers 204 with nothing.
     */
    public function delete(int|string $key): void
    {
        $this->apiDelete($this->path($key));
    }

    private function path(int|string $key): string
    {
        if (is_int($key)) {
            if ($key < 1) {
                throw new InvalidArgumentException(
                    sprintf('An SSH key id must be a positive integer; %d was given.', $key)
                );
            }

            return 'account/keys/' . $key;
        }

        $key = trim($key);

        if ($key === '') {
            throw new InvalidArgumentException('An SSH key id or fingerprint is required.');
        }

        // Encoded, but with the colon put back. A SHA256 fingerprint is `SHA256:` followed by
        // unpadded base64, and base64 contains `+` and `/` - a raw `/` would change the path
        // and address a different endpoint, so encoding is not optional. The colon is a legal
        // path character (RFC 3986 pchar), and leaving it encoded makes a fingerprint URL that
        // does not match what the API's own documentation shows.
        return 'account/keys/' . str_replace('%3A', ':', rawurlencode($key));
    }
}
