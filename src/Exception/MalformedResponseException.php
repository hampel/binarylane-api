<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\Support\Json;
use Psr\Http\Message\ResponseInterface;

/**
 * BinaryLane answered successfully with something this package cannot act on.
 *
 * This is not pedantry about content types, it is the failure mode that matters most in a
 * client whose answers are mostly lists. A maintenance page, a proxy's error document, a WAF
 * challenge and a truncated response are all a 200 with something other than the expected
 * body in it - and read permissively they become an empty array, which reaches the caller as
 * "this account has no servers". Acting on that answer is the accident this type exists to
 * prevent.
 *
 * THREE THINGS REACH HERE, and the second was a hole in 0.1.0:
 *
 *  - a 2xx whose body is not JSON at all, including an EMPTY body on any status but the two
 *    below - see forResponse();
 *  - a 2xx that parsed and does not carry the envelope key it should - see missingKey().
 *    A body that decoded and lacks `server` is somebody else's answer as surely as one that
 *    did not decode;
 *  - an action whose `status` this package cannot classify, which `Actions::await()` would
 *    otherwise poll until it timed out - see unusableActionStatus().
 *
 * THE EMPTY BODIES THIS API SENDS ON PURPOSE DO NOT COME HERE. A 204 on a delete and a 202 on
 * an accepted server action are both bodiless by design, and the specification declares an
 * empty body for those two statuses and no other: all 104 of its 200s carry a content schema.
 * Connection answers those with an empty ApiResponse.
 *
 * It extends ApiException so an existing `catch (ApiException)` sees it, even though nothing
 * was rejected.
 */
final class MalformedResponseException extends ApiException
{
    /**
     * How much of an unexpected body to quote back.
     */
    public const EXCERPT = 200;

    public static function forResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        string $body,
    ): self {
        $excerpt = trim(substr($body, 0, self::EXCERPT));

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

    /**
     * The body parsed and does not carry the key the endpoint answers with.
     *
     * Names the keys that WERE present, because that is what tells a reader whether they have
     * met a proxy, an API change, or their own typo in a hand-built `connection()` call.
     *
     * @param  array<mixed>  $data  the decoded body
     */
    public static function missingKey(int $status, string $key, array $data): self
    {
        $present = [];

        foreach (array_keys($data) as $name) {
            if (is_string($name)) {
                $present[] = $name;
            }
        }

        return new self(
            sprintf(
                'BinaryLane answered HTTP %d without the expected "%s" key. The body carried: %s',
                $status,
                $key,
                $present === [] ? 'nothing' : implode(', ', $present)
            ),
            $status,
            null,
            substr(Json::encode($data), 0, self::EXCERPT)
        );
    }

    /**
     * An action arrived whose status cannot be classified, so nothing can be concluded from it.
     *
     * RAISED RATHER THAN WAITED OUT. `Actions::await()` decides what to do next from the
     * status; a status it does not recognise is neither running nor finished, and treating it
     * as running polls a meaningless action until the deadline and then reports a timeout of
     * something that may never have existed.
     *
     * Two causes, and the message names both because the response cannot tell them apart: the
     * API has added a status since this release, or what came back was not really an action.
     */
    public static function unusableActionStatus(int $actionId, mixed $status): self
    {
        return new self(
            sprintf(
                'The BinaryLane action #%d reports a status this package cannot classify (%s), so '
                    . 'whether it has finished cannot be decided. Either the API has added a status '
                    . 'since this release, or the response was not an action.',
                $actionId,
                is_scalar($status) ? '"' . $status . '"' : get_debug_type($status)
            ),
            200,
            null,
            ''
        );
    }
}
