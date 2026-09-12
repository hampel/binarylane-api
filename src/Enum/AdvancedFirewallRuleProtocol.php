<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Which protocol an advanced firewall rule matches.
 *
 * PORTS ONLY MEAN SOMETHING FOR TCP AND UDP. A rule on `icmp` or `all` that also names a
 * port range is asking for something the protocol has no concept of.
 */
enum AdvancedFirewallRuleProtocol: string
{
    case All = 'all';
    case Icmp = 'icmp';
    case Tcp = 'tcp';
    case Udp = 'udp';

    /**
     * Whether a port range is meaningful for this protocol.
     */
    public function usesPorts(): bool
    {
        return $this === self::Tcp || $this === self::Udp;
    }
}
