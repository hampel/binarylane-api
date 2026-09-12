<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\AdvancedFirewallRuleAction;
use Hampel\BinaryLane\Api\Enum\AdvancedFirewallRuleProtocol;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One rule in a server's advanced firewall.
 *
 * THE RULE SET IS REPLACED WHOLE, NOT APPENDED TO. `ChangeAdvancedFirewallRules` takes the
 * complete list and the server ends up with exactly that - so adding one rule means fetching
 * the current set, appending, and sending all of them. Sending one rule deletes the rest.
 *
 * ORDER MATTERS, as it does in any firewall: the rules are evaluated in the order given, so
 * the position a rule is appended at is part of what it does.
 *
 * `destinationPorts` IS ONLY MEANINGFUL FOR TCP AND UDP - see
 * AdvancedFirewallRuleProtocol::usesPorts() - and null or empty means every port, which is
 * not the same as no ports. On a `drop` rule that difference is the difference between
 * blocking one service and blocking everything.
 */
final class AdvancedFirewallRule implements \JsonSerializable
{
    /**
     * Where the specification's "any address" is written.
     */
    public const ANY_IPV4 = '0.0.0.0/0';

    /**
     * @param  list<string>  $sourceAddresses  an address, a CIDR range, or ANY_IPV4
     * @param  list<string>  $destinationAddresses
     * @param  list<string>|null  $destinationPorts  null or empty matches every port
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $sourceAddresses = [],
        public readonly array $destinationAddresses = [],
        public readonly ?AdvancedFirewallRuleProtocol $protocol = null,
        public readonly ?AdvancedFirewallRuleAction $action = null,
        public readonly ?array $destinationPorts = null,
        public readonly ?string $description = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::strings($row['source_addresses'] ?? null),
            Cast::strings($row['destination_addresses'] ?? null),
            AdvancedFirewallRuleProtocol::tryFrom(Cast::string($row['protocol'] ?? null) ?? ''),
            AdvancedFirewallRuleAction::tryFrom(Cast::string($row['action'] ?? null) ?? ''),
            is_array($row['destination_ports'] ?? null) ? Cast::strings($row['destination_ports']) : null,
            Cast::string($row['description'] ?? null),
            $row,
        );
    }

    /**
     * Whether this rule matches every port - which is what a null or empty port list means.
     */
    public function matchesAllPorts(): bool
    {
        return $this->destinationPorts === null || $this->destinationPorts === [];
    }

    /**
     * Whether the rule's ports mean anything, given its protocol.
     */
    public function portsAreMeaningful(): bool
    {
        return $this->protocol?->usesPorts() ?? false;
    }

    public function drops(): bool
    {
        return $this->action === AdvancedFirewallRuleAction::Drop;
    }

    /**
     * The payload the change action takes for this rule.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'source_addresses' => $this->sourceAddresses,
            'destination_addresses' => $this->destinationAddresses,
            'protocol' => $this->protocol?->value,
            'action' => $this->action?->value,
        ];

        if ($this->destinationPorts !== null) {
            $payload['destination_ports'] = $this->destinationPorts;
        }

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        return $payload;
    }

    /**
     * A single line describing the rule, in the order a firewall reads it.
     */
    public function describe(): string
    {
        return sprintf(
            '%s %s from %s to %s%s',
            strtoupper($this->action->value ?? '?'),
            $this->protocol->value ?? '?',
            implode(',', $this->sourceAddresses) ?: 'nowhere',
            implode(',', $this->destinationAddresses) ?: 'nowhere',
            $this->matchesAllPorts() ? ' on any port' : ' on ' . implode(',', (array) $this->destinationPorts)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : $this->toArray();
    }
}
