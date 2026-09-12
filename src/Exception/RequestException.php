<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never got an answer: DNS, TLS, a timeout, a refused connection.
 *
 * Distinct from every other exception here, all of which mean BinaryLane replied and the
 * reply was not a success. A retry is reasonable for this one and frequently is not for the
 * others - and on this API that distinction has teeth, because a mutation that timed out may
 * still have been accepted. See Endpoint\Actions for how to find out rather than guess.
 */
final class RequestException extends BinaryLaneException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf('Could not reach the BinaryLane API for %s %s: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
