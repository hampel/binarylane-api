<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * How a server should be set up after an image is installed on it - the specification's
 * `ImageOptions`, used by Rebuild and by the image change inside a Resize.
 *
 * THE SAME FOUR DEFAULTS AS A CREATE, and they surprise people the same way. Leaving each
 * field unset means:
 *
 *  - no name: the auto-generated permalink is used as the hostname;
 *  - no password: one is generated and EMAILED TO THE ACCOUNT, not returned;
 *  - no SSH keys: every key marked as default on the account is deployed. An EMPTY ARRAY
 *    deploys none, which is a different thing - withoutSshKeys() sends that;
 *  - no user-data: none is applied.
 *
 * A REBUILD DISCARDS THE SERVER'S DISKS, so these are not adjustments to a running server:
 * they are how the replacement is built. Whatever was configured inside the old one is gone.
 */
final class ImageOptions implements \JsonSerializable
{
    public const MAX_USER_DATA = 65536;

    /**
     * @param  array<string, mixed>  $options
     */
    private function __construct(
        private readonly array $options = [],
    ) {
    }

    /**
     * Nothing set - every default above applies.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * The hostname for the rebuilt server.
     */
    public function withName(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'An empty hostname is not the same as none: omit withName() to keep the permalink.'
            );
        }

        return $this->with('name', $name);
    }

    /**
     * Set the default remote user's password, rather than having one generated and emailed.
     */
    public function withPassword(#[\SensitiveParameter] string $password): self
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException(
                'An empty password is not the same as none: omit withPassword() to have one generated.'
            );
        }

        return $this->with('password', $password);
    }

    /**
     * Which SSH keys to deploy, by id or fingerprint.
     *
     * @param  list<int|string>  $keys
     */
    public function withSshKeys(array $keys): self
    {
        return $this->with('ssh_keys', array_values($keys));
    }

    /**
     * Deploy no SSH keys - an empty array, which is not the same as omitting the field.
     */
    public function withoutSshKeys(): self
    {
        return $this->with('ssh_keys', []);
    }

    /**
     * cloud-init user-data. Only for an image that supports it; at most 64 KiB.
     */
    public function withUserData(#[\SensitiveParameter] string $userData): self
    {
        if (strlen($userData) > self::MAX_USER_DATA) {
            throw new InvalidArgumentException(sprintf(
                'BinaryLane accepts at most %d bytes of user-data; %d were given.',
                self::MAX_USER_DATA,
                strlen($userData)
            ));
        }

        return $this->with('user_data', $userData);
    }

    /**
     * Check what has been asked for against what the image can actually use.
     */
    public function validateAgainst(Image $image): self
    {
        $info = $image->distributionInfo;

        if ($info === null) {
            return $this;
        }

        if (isset($this->options['user_data']) && !$info->supportsUserData()) {
            throw new InvalidArgumentException(sprintf(
                'The image "%s" does not support user-data, and the specification requires the '
                    . 'field to be null for such an image.',
                $image->describe()
            ));
        }

        if (!empty($this->options['ssh_keys']) && !$info->supportsSshKeys()) {
            throw new InvalidArgumentException(sprintf(
                'The image "%s" does not support SSH keys, so deploying them would do nothing.',
                $image->describe()
            ));
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $options = $this->options;

        foreach (['password', 'user_data'] as $secret) {
            if (isset($options[$secret])) {
                $options[$secret] = '(withheld)';
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->options;
    }

    private function with(string $key, mixed $value): self
    {
        return new self([...$this->options, $key => $value]);
    }
}
