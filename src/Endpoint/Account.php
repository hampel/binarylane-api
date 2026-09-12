<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Account as AccountEntity;

/**
 * The account the token belongs to.
 *
 * `/v2/account`
 *
 * One operation, and it is the one to call at startup: it is the only request that answers
 * "does this token work" without changing anything, and its answer carries the three facts -
 * status, email verification, additional IPv4 limit - that make later requests fail for
 * reasons that have nothing to do with the request. See Client::verify().
 *
 * SSH KEYS LIVE UNDER `/v2/account/keys` AND ARE NOT HERE. The specification files them
 * under their own tag, and this package follows that: see Endpoint\SshKeys.
 */
final class Account extends Endpoint
{
    /**
     * The account. Raises NotAuthenticatedException when the token is not usable.
     */
    public function get(): AccountEntity
    {
        return $this->apiObject('account', 'account', AccountEntity::fromArray(...));
    }
}
