<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * What the account owes and what it has, in AU$.
 *
 * TWO NUMBERS THAT DO NOT NET OFF BY THEMSELVES. `unbilledTotal` is what has accrued since
 * the last invoice; `availableCredit` is what is sitting there to pay it with. Neither is a
 * balance in the accounting sense, and the interesting question - will the next invoice be
 * covered - is the difference. `shortfall()` is that subtraction, done once here rather than
 * at each call site with the sign the wrong way round.
 */
final class Balance implements \JsonSerializable
{
    /**
     * @param  list<ChargeInformation>  $charges
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly float $unbilledTotal = 0.0,
        public readonly float $availableCredit = 0.0,
        public readonly array $charges = [],
        public readonly ?\DateTimeImmutable $generatedAt = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::float($row['unbilled_total'] ?? null) ?? 0.0,
            Cast::float($row['available_credit'] ?? null) ?? 0.0,
            Cast::objects($row['charges'] ?? null, ChargeInformation::fromArray(...)),
            Cast::datetime($row['generated_at'] ?? null),
            $row,
        );
    }

    /**
     * How much more is owed than there is credit for. Zero when the credit covers it.
     *
     * The number worth alerting on: a positive shortfall means the next invoice will need a
     * payment to go through, and a failed payment blocks new services - see
     * Endpoint\Billing::unpaidFailedInvoices().
     */
    public function shortfall(): float
    {
        return max(0.0, $this->unbilledTotal - $this->availableCredit);
    }

    /**
     * Whether the available credit covers what has accrued so far.
     */
    public function isCovered(): bool
    {
        return $this->shortfall() <= 0.0;
    }

    /**
     * The charges for services that will recur - what next month looks like, roughly.
     *
     * @return list<ChargeInformation>
     */
    public function ongoingCharges(): array
    {
        return array_values(array_filter(
            $this->charges,
            static fn (ChargeInformation $charge): bool => $charge->ongoing
        ));
    }

    /**
     * The total of the recurring charges.
     */
    public function ongoingTotal(): float
    {
        $total = 0.0;

        foreach ($this->ongoingCharges() as $charge) {
            $total += $charge->total;
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'unbilled_total' => $this->unbilledTotal,
            'available_credit' => $this->availableCredit,
            'charges' => $this->charges,
            'generated_at' => $this->generatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
