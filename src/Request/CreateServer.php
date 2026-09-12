<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Request;

use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Size;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Everything a new server needs - the specification's `CreateServerRequest`.
 *
 * Three fields are required and the rest have defaults, so the short form is short:
 *
 *     CreateServer::of('std-min', 'ubuntu-24-04-lts', 'syd')
 *         ->withName('vps01.example.com')
 *         ->withSshKeys([12345])
 *         ->withOptions(SizeOptions::none()->withMemory(4096));
 *
 * FOUR DEFAULTS ARE WORTH KNOWING BEFORE YOU MEET THEM, because each is a decision made for
 * you by leaving a field null:
 *
 *  - NO NAME MEANS A RANDOM ONE. The server gets a generated hostname, not an empty one.
 *  - NO PASSWORD MEANS A RANDOM PASSWORD EMAILED TO THE ACCOUNT. Nothing is returned in the
 *    response, so a provisioning script that does not set one and does not deploy an SSH key
 *    has no way to reach the server it just made.
 *  - NO SSH KEYS MEANS THE DEFAULT-MARKED ONES. Not "none" - every key marked as default on
 *    the account is deployed. `withoutSshKeys()` is how to actually deploy none, and it sends
 *    an empty array rather than null, because those mean opposite things here.
 *  - PORT BLOCKING IS ON. Outgoing TCP 22, 25 and 3389 are blocked on every new server.
 *    Turning it off is only permitted on a VERIFIED account, so an unverified one gets a 400
 *    naming the field rather than the reason.
 *
 * `user_data` MUST BE NULL FOR AN IMAGE THAT DOES NOT SUPPORT IT, says the specification -
 * check DistributionInfo::supportsUserData() first. validateAgainst() does that and the size
 * and region checks in one go.
 */
final class CreateServer implements \JsonSerializable
{
    /**
     * What the API accepts in `user_data`, in bytes.
     */
    public const MAX_USER_DATA = 65536;

    /**
     * @param  int|string  $image  a slug or an id, which is what the API takes
     * @param  array<string, mixed>  $optional  only the fields actually set
     */
    private function __construct(
        public readonly string $size,
        public readonly int|string $image,
        public readonly string $region,
        private readonly array $optional = [],
    ) {
    }

    /**
     * The three required choices: what to build, what to build it from, and where.
     *
     * @param  int|string  $image  an image slug (`ubuntu-24-04-lts`) or id. A BACKUP has no
     *                             slug, so restoring one into a new server means its id
     */
    public static function of(string $size, int|string $image, string $region): self
    {
        $size = trim($size);
        $region = trim($region);

        if ($size === '') {
            throw new InvalidArgumentException('A new server needs a size slug.');
        }

        if ($region === '') {
            throw new InvalidArgumentException('A new server needs a region slug.');
        }

        if (is_string($image) && trim($image) === '') {
            throw new InvalidArgumentException('A new server needs an image slug or id.');
        }

        return new self($size, is_string($image) ? trim($image) : $image, $region);
    }

    /**
     * The same, from fetched objects rather than slugs - which also checks that the size is
     * actually offered in that region before a request is spent finding out.
     */
    public static function for(Size $size, Image $image, string $region): self
    {
        $region = trim($region);

        if (!$size->isAvailableIn($region)) {
            throw new InvalidArgumentException(sprintf(
                'The size "%s" cannot be used in "%s" right now: %s.',
                $size->slug,
                $region,
                match (true) {
                    !$size->available => 'the size is not available for new servers anywhere',
                    !$size->isOfferedIn($region) => 'it is not offered in that region',
                    default => 'it is out of stock there',
                }
            ));
        }

        if (!$image->isAvailableIn($region)) {
            throw new InvalidArgumentException(sprintf(
                'The image "%s" is not available in "%s".',
                $image->describe(),
                $region
            ));
        }

        return self::of($size->slug, $image->reference(), $region);
    }

    /**
     * The hostname. Left unset, BinaryLane generates a random one.
     */
    public function withName(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'An empty hostname is not the same as none: omit withName() to get a generated one.'
            );
        }

        return $this->with('name', $name);
    }

    /**
     * Set the default remote user's password.
     *
     * Left unset, a random password is generated and EMAILED TO THE ACCOUNT ADDRESS - it is
     * not in the response, so nothing programmatic ever sees it.
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
     * Which SSH keys to deploy, by id or by fingerprint.
     *
     * Left unset, every key marked as default on the account is deployed. Pass an empty list,
     * or call withoutSshKeys(), to deploy none.
     *
     * @param  list<int|string>  $keys
     */
    public function withSshKeys(array $keys): self
    {
        return $this->with('ssh_keys', array_values($keys));
    }

    /**
     * Deploy no SSH keys at all.
     *
     * Sends an empty array, which the specification distinguishes from null: null deploys the
     * account's default keys.
     */
    public function withoutSshKeys(): self
    {
        return $this->with('ssh_keys', []);
    }

    /**
     * cloud-init user-data.
     *
     * ONLY FOR AN IMAGE THAT SUPPORTS IT - see DistributionInfo::supportsUserData(). It is
     * also capped at 64 KiB, and it commonly carries secrets, so it does not belong in a log.
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
     * Turn on two daily backups.
     *
     * A SHORTHAND THAT LOSES TO `options`. The specification says `options.daily_backups`
     * overrides this field, so setting both and disagreeing is resolved in favour of the
     * options. Use SizeOptions::withDailyBackups() when you want a particular number.
     */
    public function withBackups(bool $enabled = true): self
    {
        return $this->with('backups', $enabled);
    }

    /**
     * Enable IPv6.
     */
    public function withIpv6(bool $enabled = true): self
    {
        return $this->with('ipv6', $enabled);
    }

    /**
     * Put the server in a VPC rather than on the region's public network.
     */
    public function inVpc(int $vpcId, ?string $ipv4Address = null): self
    {
        if ($vpcId < 1) {
            throw new InvalidArgumentException('A VPC id must be positive.');
        }

        $request = $this->with('vpc_id', $vpcId);

        return $ipv4Address === null ? $request : $request->with('vpc_ipv4_address', trim($ipv4Address));
    }

    /**
     * Give the server a network interface dedicated to its VPC traffic.
     */
    public function withSeparatePrivateNetworkInterface(bool $enabled = true): self
    {
        return $this->with('separate_private_network_interface', $enabled);
    }

    /**
     * Turn off the default blocking of outgoing TCP 22, 25 and 3389.
     *
     * ONLY VERIFIED ACCOUNTS MAY DO THIS. On an unverified one the request comes back as a
     * 400 about the field, which reads like a malformed value and is not.
     */
    public function withPortBlocking(bool $enabled): self
    {
        return $this->with('port_blocking', $enabled);
    }

    /**
     * The add-ons - memory, disk, transfer, addresses, backup retention.
     */
    public function withOptions(SizeOptions $options): self
    {
        return $options->isEmpty() ? $this : $this->with('options', $options->toArray());
    }

    /**
     * Software licences to buy with the server.
     *
     * @param  list<License>  $licenses
     */
    public function withLicenses(array $licenses): self
    {
        return $this->with('licenses', array_map(
            static fn (License $license): array => $license->toArray(),
            array_values($licenses)
        ));
    }

    /**
     * Check the request against what the chosen image and size actually permit.
     *
     * Catches the three mismatches that are decided by data the caller already has, and that
     * the API reports as a 400 naming a field: user-data on an image that ignores it, SSH keys
     * on an image that cannot use them, and options outside the size's limits.
     */
    public function validateAgainst(Image $image, ?Size $size = null): self
    {
        $info = $image->distributionInfo;

        if ($info !== null && isset($this->optional['user_data']) && !$info->supportsUserData()) {
            throw new InvalidArgumentException(sprintf(
                'The image "%s" does not support user-data, and the specification requires the '
                    . 'field to be null for such an image.',
                $image->describe()
            ));
        }

        if ($info !== null && !empty($this->optional['ssh_keys']) && !$info->supportsSshKeys()) {
            throw new InvalidArgumentException(sprintf(
                'The image "%s" does not support SSH keys, so deploying them would do nothing. '
                    . 'Use withPassword(), or withoutSshKeys() to be explicit.',
                $image->describe()
            ));
        }

        if ($size !== null && isset($this->optional['options']) && is_array($this->optional['options'])) {
            $options = SizeOptions::none();

            foreach ($this->optional['options'] as $key => $value) {
                $options = match ($key) {
                    'memory' => $options->withMemory(Cast::int($value) ?? 0),
                    'disk' => $options->withDisk(Cast::int($value) ?? 0),
                    'transfer' => $options->withTransfer(Cast::float($value) ?? 0.0),
                    'ipv4_addresses' => $options->withIpv4Addresses(Cast::int($value) ?? 0),
                    default => $options,
                };
            }

            $options->validateAgainst($size);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'image' => $this->image,
            'region' => $this->region,
            ...$this->optional,
        ];
    }

    /**
     * The password and the user-data are withheld: both are credentials, and this is what a
     * var_dump or a stack trace prints.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $payload = $this->toArray();

        foreach (['password', 'user_data'] as $secret) {
            if (isset($payload[$secret])) {
                $payload[$secret] = '(withheld)';
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function with(string $key, mixed $value): self
    {
        return new self($this->size, $this->image, $this->region, [...$this->optional, $key => $value]);
    }
}
