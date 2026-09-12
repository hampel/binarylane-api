<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

/**
 * A 4xx this package does not name specifically.
 *
 * The concrete types cover every status the specification declares - 400, 401, 403, 404 -
 * so reaching here means the API has started using one it did not document, which is worth
 * knowing about rather than having quietly absorbed into a broader class.
 */
final class ClientException extends ApiException
{
}
