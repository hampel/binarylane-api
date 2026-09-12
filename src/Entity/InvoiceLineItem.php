<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One line on an invoice.
 *
 * A NEGATIVE AMOUNT IS A DISCOUNT OR A CREDIT, says the specification - so summing the lines
 * without regard to sign overstates the invoice.
 *
 * `amountIncludesTax` IS PER LINE, not per invoice, so an invoice can mix lines that do and
 * do not. Adding tax to every line because one of them excluded it is the mistake this field
 * exists to prevent.
 */
final class InvoiceLineItem implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $name = '',
        public readonly float $amount = 0.0,
        public readonly bool $amountIncludesTax = false,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['name'] ?? null) ?? '',
            Cast::float($row['amount'] ?? null) ?? 0.0,
            Cast::bool($row['amount_includes_tax'] ?? null) ?? false,
            $row,
        );
    }

    /**
     * Whether this line is a discount or a credit rather than a charge.
     */
    public function isCredit(): bool
    {
        return $this->amount < 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'name' => $this->name,
            'amount' => $this->amount,
            'amount_includes_tax' => $this->amountIncludesTax,
        ];
    }
}
