<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\AccountStatus;
use Hampel\BinaryLane\Api\Enum\PaymentMethod;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The account the API token belongs to.
 *
 * THE CHEAPEST CHECK THAT A TOKEN WORKS. `GET /v2/account` is a single request that either
 * answers with this or raises NotAuthenticatedException, and it is what Client::verify()
 * calls. There is nothing to read about the TOKEN here, because BinaryLane tokens carry no
 * scopes and no expiry - what comes back is about the account, which is the only thing there
 * is to know.
 *
 * Three of these fields are the reason to look before a provisioning run rather than after:
 * an account that is not `active`, one whose email is unverified - "unverified accounts are
 * subject to some restrictions", says the specification, without listing them - and an
 * `additionalIpv4Limit` that a batch of servers is about to exceed. All three produce a 400
 * later whose message is about a field rather than about the account.
 */
final class Account implements \JsonSerializable
{
    /**
     * @param  list<PaymentMethod>  $configuredPaymentMethods
     * @param  list<string>  $unknownPaymentMethods  values the API sent that this package has
     *                                               no case for - see fromArray()
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $email = '',
        public readonly bool $emailVerified = false,
        public readonly bool $twoFactorAuthenticationEnabled = false,
        public readonly ?AccountStatus $status = null,
        public readonly ?TaxCode $taxCode = null,
        public readonly array $configuredPaymentMethods = [],
        public readonly int $additionalIpv4Limit = 0,
        public readonly array $unknownPaymentMethods = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * A payment method this package does not recognise is kept as a string rather than
     * dropped.
     *
     * The alternative - silently discarding it - would have an account with a new payment
     * type look like an account with none, and "no configured payment method" is exactly the
     * condition an integration is likely to act on.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $methods = [];
        $unknown = [];

        foreach (Cast::strings($row['configured_payment_methods'] ?? null) as $value) {
            $method = PaymentMethod::tryFrom($value);

            if ($method !== null) {
                $methods[] = $method;
            } else {
                $unknown[] = $value;
            }
        }

        return new self(
            Cast::string($row['email'] ?? null) ?? '',
            Cast::bool($row['email_verified'] ?? null) ?? false,
            Cast::bool($row['two_factor_authentication_enabled'] ?? null) ?? false,
            AccountStatus::tryFrom(Cast::string($row['status'] ?? null) ?? ''),
            Cast::nested($row['tax_code'] ?? null, TaxCode::fromArray(...)),
            $methods,
            Cast::int($row['additional_ipv4_limit'] ?? null) ?? 0,
            $unknown,
            $row,
        );
    }

    /**
     * Whether the account is in the one state where everything is expected to work.
     *
     * False for a status this package does not recognise as well as for the three that are
     * not `active`, which is the conservative way round.
     */
    public function isActive(): bool
    {
        return $this->status?->isActive() ?? false;
    }

    /**
     * Whether there is any way to pay for what is about to be created.
     *
     * An account with no payment method configured is the commonest reason for the
     * `incomplete` status.
     */
    public function canBeBilled(): bool
    {
        return $this->configuredPaymentMethods !== [] || $this->unknownPaymentMethods !== [];
    }

    public function usesPaymentMethod(PaymentMethod $method): bool
    {
        return in_array($method, $this->configuredPaymentMethods, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'two_factor_authentication_enabled' => $this->twoFactorAuthenticationEnabled,
            'status' => $this->status?->value,
            'tax_code' => $this->taxCode,
            'configured_payment_methods' => array_map(
                static fn (PaymentMethod $method): string => $method->value,
                $this->configuredPaymentMethods
            ),
            'additional_ipv4_limit' => $this->additionalIpv4Limit,
        ];
    }
}
