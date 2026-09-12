<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Authentication;

use Psr\Http\Message\RequestInterface;

/**
 * How a request proves who it is.
 *
 * There is one implementation, because BinaryLane has one wire format and one kind of
 * credential: `Authorization: Bearer <api-token>`, issued from the control panel, with no
 * scopes and no expiry. The interface is here anyway, for the two things that will want to
 * sit behind it - a credential resolved per tenant at request time rather than at
 * construction, and a token read from a secret store that rotates underneath the process.
 */
interface Authentication
{
    public function applyTo(RequestInterface $request): RequestInterface;

    /**
     * What this credential is, for a log line or an exception message.
     *
     * MUST NOT INCLUDE THE TOKEN, or any part of it long enough to be useful. A credential
     * ends up in an error message far more often than anyone intends, and this method is the
     * reason a token does not.
     */
    public function describe(): string;
}
