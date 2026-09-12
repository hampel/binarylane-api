<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * No such thing - HTTP 404.
 *
 * Raised for an object that is not there and for a path the API does not have, which are not
 * distinguished in the response.
 *
 * A PATH THE API DOES NOT HAVE ANSWERS WITH NO BODY AT ALL, despite the specification
 * declaring `ProblemDetails` for every 404 it documents. Measured on 12 September 2026:
 * `GET /v2/no-such-thing` returns `HTTP 404` with `content-length: 0`. So `problem` is null
 * on a mistyped path and populated on a real object that is missing - which makes it a rough
 * signal for telling a bug in your code from a gap in the account's data.
 *
 * IT ALSO COVERS AN OBJECT THAT BELONGS TO SOMEONE ELSE. A server id from another account
 * answers 404, not 403 - there is no way from the outside to tell "deleted" from "never
 * yours", and that is deliberate on BinaryLane's part rather than an omission.
 *
 * The endpoint helpers that treat absence as an ordinary answer catch this and return null
 * instead; see Endpoint::apiFind().
 */
final class NotFoundException extends ApiException
{
}
