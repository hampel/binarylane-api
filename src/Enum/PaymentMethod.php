<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * A payment method configured on the account.
 */
enum PaymentMethod: string
{
    /** A validated credit card. */
    case CreditCard = 'credit-card';

    case PayPal = 'paypal';
}
