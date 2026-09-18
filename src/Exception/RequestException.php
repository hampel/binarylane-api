<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request got no answer: DNS, TLS, a refused connection, or a timeout.
 *
 * NO ANSWER IS NOT "NOT DONE". A timeout can arrive after BinaryLane received the request and
 * carried it out, with only the reply lost - this API has been observed to complete zone
 * creations whose answers never arrived. PSR-18 does not say which side of that line a failure
 * fell on; getPrevious() holds whatever the HTTP client itself knew, and some clients do
 * distinguish a connect timeout from a read timeout.
 *
 * So a read can be retried freely, and a write cannot be retried blindly. Find out first:
 * re-read the resource the write would have created or changed, or for a mutation that
 * answers with an action, look for it with Endpoint\Actions. A retried create that lands
 * twice, or that fails because the first one landed, is the same failure one step later.
 *
 * Distinct from every other exception here, all of which mean BinaryLane replied and the reply
 * was not a success.
 */
final class RequestException extends BinaryLaneException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf(
                'No answer from the BinaryLane API for %s %s, so whether it was carried out is unknown: %s',
                $method,
                $uri,
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}
