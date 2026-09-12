<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * What kind of thing an action was about.
 *
 * Read together with the action's `resource_id`: the pair is the only link from an action
 * back to what it operated on, and either may be null for an action that operated on nothing
 * in particular.
 */
enum ResourceType: string
{
    case Server = 'server';
    case LoadBalancer = 'load-balancer';
    case SshKey = 'ssh-key';

    /** A virtual private cloud. The specification spells the description "Virtual Private Network". */
    case Vpc = 'vpc';

    /** A backup or an operating system image. */
    case Image = 'image';

    case RegisteredDomainName = 'registered-domain-name';
}
