<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What an advanced firewall rule does with traffic it matches.
 */
enum AdvancedFirewallRuleAction: string
{
    /** Silently discarded. */
    case Drop = 'drop';

    /** Allowed through. */
    case Accept = 'accept';
}
