<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * How tax is applied to the account's transactions.
 */
enum TaxCodeType: string
{
    /** No tax on any transaction. */
    case None = 'none';

    /** A fixed fraction of each transaction's value is added - see TaxCode::$rate. */
    case Scalar = 'scalar';
}
