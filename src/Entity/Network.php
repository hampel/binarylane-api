<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\NetworkType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One address assigned to a server.
 *
 * `netmask` IS EITHER A STRING OR AN INTEGER, and the specification says so - it is declared
 * `oneOf: [integer, string]`. In practice an IPv4 network reports a dotted mask
 * (`255.255.255.0`) and an IPv6 network reports a prefix length (`64`), so a caller that
 * assumed one shape works until the first IPv6 address arrives. It is kept as given here, and
 * prefixLength() is the normalised form for when the number is what you wanted.
 *
 * `natTarget` IS THE INTERESTING ONE FOR A VPC. When it is not null, packets addressed to
 * this address are delivered to that private address instead - which is how a public address
 * reaches a server that only has a private interface.
 */
final class Network implements \JsonSerializable
{
    /**
     * @param  int|string|null  $netmask  a dotted mask for IPv4, a prefix length for IPv6
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $ipAddress = '',
        public readonly ?NetworkType $type = null,
        public readonly int|string|null $netmask = null,
        public readonly ?string $gateway = null,
        public readonly ?string $reverseName = null,
        public readonly ?string $natTarget = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $netmask = $row['netmask'] ?? null;

        return new self(
            Cast::string($row['ip_address'] ?? null) ?? '',
            NetworkType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            is_int($netmask) ? $netmask : Cast::string($netmask),
            Cast::string($row['gateway'] ?? null),
            Cast::string($row['reverse_name'] ?? null),
            Cast::string($row['nat_target'] ?? null),
            $row,
        );
    }

    public function isPublic(): bool
    {
        return $this->type?->isPublic() ?? false;
    }

    public function isPrivate(): bool
    {
        return $this->type === NetworkType::Private;
    }

    /**
     * Whether the address is IPv6, by inspection of the address itself rather than by which
     * list it arrived in - so a Network held on its own can still answer.
     */
    public function isIpv6(): bool
    {
        return filter_var($this->ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function isIpv4(): bool
    {
        return filter_var($this->ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * The netmask as a prefix length, whichever way it arrived.
     *
     * A dotted IPv4 mask is counted; an integer is returned as it stands. Null when there was
     * no netmask, or when it was a string that is not a valid mask.
     */
    public function prefixLength(): ?int
    {
        if (is_int($this->netmask)) {
            return $this->netmask;
        }

        if ($this->netmask === null) {
            return null;
        }

        if (ctype_digit($this->netmask)) {
            return (int) $this->netmask;
        }

        $packed = @inet_pton($this->netmask);

        if ($packed === false) {
            return null;
        }

        $bits = 0;

        foreach (str_split($packed) as $byte) {
            $bits += substr_count(decbin(ord($byte)), '1');
        }

        return $bits;
    }

    /**
     * The address with its prefix - `203.0.113.10/24` - for a display or a firewall rule.
     */
    public function cidr(): string
    {
        $prefix = $this->prefixLength();

        return $prefix === null ? $this->ipAddress : $this->ipAddress . '/' . $prefix;
    }

    /**
     * Whether traffic to this address is forwarded to a private address behind it.
     */
    public function isNatted(): bool
    {
        return $this->natTarget !== null && trim($this->natTarget) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'ip_address' => $this->ipAddress,
            'type' => $this->type?->value,
            'netmask' => $this->netmask,
            'gateway' => $this->gateway,
            'reverse_name' => $this->reverseName,
            'nat_target' => $this->natTarget,
        ];
    }

    public function __toString(): string
    {
        return $this->ipAddress;
    }
}
