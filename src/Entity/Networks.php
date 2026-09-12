<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Every address a server has, and the network settings that apply to all of them.
 *
 * `v4` AND `v6` EACH MIX PUBLIC AND PRIVATE. The split is by address family, not by
 * reachability, so `$networks->v4[0]` is not necessarily the internet-facing address - a
 * server in a VPC has private v4 addresses in the same list. publicV4() and privateV4() are
 * the questions worth asking.
 *
 * `recentDdos` IS A FACT WORTH SURFACING: when it is true the server has recently been the
 * target of an attack and BinaryLane has emailed the account about it. It is easy to miss in
 * a field list and it explains a great deal of otherwise inexplicable behaviour.
 *
 * `macAddress` is needed for ARP-level work and for some licence systems that tie to it.
 */
final class Networks implements \JsonSerializable
{
    /**
     * @param  list<Network>  $v4
     * @param  list<Network>  $v6
     * @param  list<string>  $ipv6ReverseNameservers
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $v4 = [],
        public readonly array $v6 = [],
        public readonly bool $portBlocking = false,
        public readonly ?bool $separatePrivateNetworkInterface = null,
        public readonly ?bool $sourceAndDestinationCheck = null,
        public readonly bool $recentDdos = false,
        public readonly array $ipv6ReverseNameservers = [],
        public readonly string $macAddress = '',
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::objects($row['v4'] ?? null, Network::fromArray(...)),
            Cast::objects($row['v6'] ?? null, Network::fromArray(...)),
            Cast::bool($row['port_blocking'] ?? null) ?? false,
            Cast::bool($row['separate_private_network_interface'] ?? null),
            Cast::bool($row['source_and_destination_check'] ?? null),
            Cast::bool($row['recent_ddos'] ?? null) ?? false,
            Cast::strings($row['ipv6_reverse_nameservers'] ?? null),
            Cast::string($row['mac_address'] ?? null) ?? '',
            $row,
        );
    }

    /**
     * Every address, both families, in one list.
     *
     * @return list<Network>
     */
    public function all(): array
    {
        return [...$this->v4, ...$this->v6];
    }

    /**
     * The internet-facing IPv4 addresses.
     *
     * @return list<Network>
     */
    public function publicV4(): array
    {
        return array_values(array_filter($this->v4, static fn (Network $n): bool => $n->isPublic()));
    }

    /**
     * The IPv4 addresses that are not reachable from the internet - a VPC or LAN address.
     *
     * @return list<Network>
     */
    public function privateV4(): array
    {
        return array_values(array_filter($this->v4, static fn (Network $n): bool => $n->isPrivate()));
    }

    /**
     * @return list<Network>
     */
    public function publicV6(): array
    {
        return array_values(array_filter($this->v6, static fn (Network $n): bool => $n->isPublic()));
    }

    /**
     * The address to connect to, or null when there is not one.
     *
     * The first public IPv4 address, falling back to the first public IPv6 - which is the
     * order anything doing SSH or HTTP wants, and the fallback matters because a server can
     * be created with no public IPv4 at all (there is a discount for it; see SizeOptions).
     */
    public function primaryPublicAddress(): ?Network
    {
        return $this->publicV4()[0] ?? $this->publicV6()[0] ?? null;
    }

    /**
     * Whether the server has any internet-facing address.
     */
    public function hasPublicAddress(): bool
    {
        return $this->primaryPublicAddress() !== null;
    }

    /**
     * Whether any address on this server carries a NAT target, which is how a public address
     * is mapped onto a server that only has a private interface.
     */
    public function hasNat(): bool
    {
        foreach ($this->all() as $network) {
            if ($network->isNatted()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'v4' => $this->v4,
            'v6' => $this->v6,
            'port_blocking' => $this->portBlocking,
            'separate_private_network_interface' => $this->separatePrivateNetworkInterface,
            'source_and_destination_check' => $this->sourceAndDestinationCheck,
            'recent_ddos' => $this->recentDdos,
            'ipv6_reverse_nameservers' => $this->ipv6ReverseNameservers,
            'mac_address' => $this->macAddress,
        ];
    }
}
