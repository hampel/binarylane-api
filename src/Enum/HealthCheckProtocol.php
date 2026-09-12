<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * How a load balancer checks whether a server is healthy.
 *
 * `both` IS PER-PROTOCOL, not "either will do": a server that fails the HTTP check is taken
 * out of the HTTP pool and left in the HTTPS one. That is the documented behaviour and it is
 * the opposite of what the word suggests.
 */
enum HealthCheckProtocol: string
{
    case Http = 'http';
    case Https = 'https';

    /** Both, checked and failed independently - see the class note. */
    case Both = 'both';
}
