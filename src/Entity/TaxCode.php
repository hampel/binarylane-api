<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\TaxCodeType;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The tax applied to the account's transactions.
 *
 * `fixedPercent` IS A PERCENTAGE, NOT A FRACTION: 10 means 10%, so the multiplier is
 * `1 + $rate / 100`. multiplier() does that arithmetic rather than leaving it at each call
 * site, because getting it wrong by a factor of a hundred produces a number that still looks
 * like money.
 */
final class TaxCode implements \JsonSerializable
{
    /**
     * @param  float|null  $fixedPercent  100 = 100%. Null when the type is `none`
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $name = '',
        public readonly ?TaxCodeType $type = null,
        public readonly ?float $fixedPercent = null,
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
            TaxCodeType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            Cast::float($row['fixed_percent'] ?? null),
            $row,
        );
    }

    /**
     * Whether any tax is added at all.
     */
    public function applies(): bool
    {
        return $this->type === TaxCodeType::Scalar && ($this->fixedPercent ?? 0.0) > 0.0;
    }

    /**
     * What to multiply a pre-tax amount by. 1.0 when no tax applies.
     */
    public function multiplier(): float
    {
        return $this->applies() ? 1.0 + ($this->fixedPercent ?? 0.0) / 100.0 : 1.0;
    }

    /**
     * The tax on an amount, in the same units as the amount.
     */
    public function on(float $amount): float
    {
        return $this->applies() ? $amount * ($this->fixedPercent ?? 0.0) / 100.0 : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'name' => $this->name,
            'type' => $this->type?->value,
            'fixed_percent' => $this->fixedPercent,
        ];
    }
}
