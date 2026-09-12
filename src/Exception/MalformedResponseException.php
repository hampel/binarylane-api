<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * A success whose body is not the JSON object this API sends.
 *
 * This is not pedantry about content types, it is the failure mode that matters most in a
 * client whose answers are mostly lists. A maintenance page, a proxy's error document, a WAF
 * challenge and a truncated response are all a 200 with something other than JSON in it -
 * and decoded permissively they become an empty array, which reaches the caller as "this
 * account has no servers". Acting on that answer is the accident this type exists to
 * prevent.
 *
 * THE EMPTY BODIES THIS API SENDS ON PURPOSE DO NOT COME HERE. A 204 on a delete and a 202
 * on an accepted server action are both bodiless by design; Connection answers those with an
 * empty ApiResponse. Only a status that should have carried a body and did not reaches this.
 *
 * It extends ApiException so an existing `catch (ApiException)` sees it, even though nothing
 * was rejected.
 */
final class MalformedResponseException extends ApiException
{
    public static function forResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        string $body,
    ): self {
        $excerpt = trim(substr($body, 0, 200));

        return new self(
            sprintf(
                'BinaryLane answered %s %s with HTTP %d but the body is not JSON (Content-Type: %s)%s',
                $method,
                $uri,
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type') ?: 'none',
                $excerpt === '' ? '; the body was empty' : ': ' . $excerpt
            ),
            $response->getStatusCode(),
            null,
            $body
        );
    }
}
