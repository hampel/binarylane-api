<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * Which protocol a load balancer forwarding rule handles.
 *
 * HTTP AND HTTPS ONLY. There is no TCP forwarding on a BinaryLane load balancer, so it is
 * not a general-purpose layer 4 balancer and anything that is not a web protocol needs
 * another approach.
 */
enum LoadBalancerRuleProtocol: string
{
    case Http = 'http';
    case Https = 'https';
}
