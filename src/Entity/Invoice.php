<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * An invoice.
 *
 * TWO IDENTIFIERS AND THEY ARE NOT INTERCHANGEABLE. `invoiceId` is the integer the API is
 * addressed by; `invoiceNumber` is the string a person quotes. Fetching by the second will
 * not work.
 *
 * THREE DATES, AND THE ONE THAT MATTERS IS THE THIRD. `created` is when it was raised,
 * `dateDue` when payment is expected, and `dateOverdue` when the account is treated as in
 * arrears - which is the one that has consequences, because an unpaid invoice with a failed
 * payment blocks new services and can hold an action indefinitely (see
 * Action::$blockingInvoiceId).
 *
 * THE TWO URLS EXPIRE AFTER 24 HOURS, says the specification, and they are unauthenticated
 * links to a document with the account's billing details on it. Fetch one when it is about to
 * be used rather than storing it.
 */
final class Invoice implements \JsonSerializable
{
    /**
     * @param  list<InvoiceLineItem>  $invoiceItems
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $invoiceNumber = '',
        public readonly float $amount = 0.0,
        public readonly float $tax = 0.0,
        public readonly ?TaxCode $taxCode = null,
        public readonly ?\DateTimeImmutable $created = null,
        public readonly ?\DateTimeImmutable $dateDue = null,
        public readonly ?\DateTimeImmutable $dateOverdue = null,
        public readonly bool $paid = false,
        public readonly bool $refunded = false,
        public readonly ?int $paymentFailureCount = null,
        public readonly array $invoiceItems = [],
        public readonly ?string $reference = null,
        public readonly ?string $invoiceDownloadUrl = null,
        public readonly ?string $invoiceViewUrl = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['invoice_id'] ?? null) ?? 0,
            Cast::string($row['invoice_number'] ?? null) ?? '',
            Cast::float($row['amount'] ?? null) ?? 0.0,
            Cast::float($row['tax'] ?? null) ?? 0.0,
            Cast::nested($row['tax_code'] ?? null, TaxCode::fromArray(...)),
            Cast::datetime($row['created'] ?? null),
            Cast::datetime($row['date_due'] ?? null),
            Cast::datetime($row['date_overdue'] ?? null),
            Cast::bool($row['paid'] ?? null) ?? false,
            Cast::bool($row['refunded'] ?? null) ?? false,
            Cast::int($row['payment_failure_count'] ?? null),
            Cast::objects($row['invoice_items'] ?? null, InvoiceLineItem::fromArray(...)),
            Cast::string($row['reference'] ?? null),
            Cast::string($row['invoice_download_url'] ?? null),
            Cast::string($row['invoice_view_url'] ?? null),
            $row,
        );
    }

    /**
     * Whether this invoice still needs paying.
     *
     * A REFUNDED INVOICE IS NOT OUTSTANDING, which is why both flags are read: an invoice can
     * be unpaid and refunded, and chasing that one would be a mistake.
     */
    public function isOutstanding(): bool
    {
        return !$this->paid && !$this->refunded;
    }

    /**
     * Whether a payment attempt has failed against this invoice.
     *
     * THE CONDITION THAT BLOCKS THINGS. The specification says an unpaid invoice with a failed
     * payment "may block the ability to renew existing services or add new services", and an
     * action held up by one waits indefinitely.
     */
    public function hasFailedPayment(): bool
    {
        return ($this->paymentFailureCount ?? 0) > 0;
    }

    /**
     * Whether the account is in arrears on this invoice.
     */
    public function isOverdue(?\DateTimeImmutable $now = null): bool
    {
        if (!$this->isOutstanding() || $this->dateOverdue === null) {
            return false;
        }

        return $this->dateOverdue <= ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * The amount before tax.
     */
    public function amountExcludingTax(): float
    {
        return $this->amount - $this->tax;
    }

    /**
     * The credit and discount lines.
     *
     * @return list<InvoiceLineItem>
     */
    public function credits(): array
    {
        return array_values(array_filter(
            $this->invoiceItems,
            static fn (InvoiceLineItem $item): bool => $item->isCredit()
        ));
    }

    /**
     * The URLs are omitted: they are unauthenticated links to a document carrying the
     * account's billing details.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'amount' => $this->amount,
            'paid' => $this->paid,
            'urls' => $this->invoiceDownloadUrl === null ? 'none' : '(withheld)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $this->invoiceNumber,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'tax' => $this->tax,
            'tax_code' => $this->taxCode,
            'created' => $this->created?->format(\DateTimeInterface::ATOM),
            'date_due' => $this->dateDue?->format(\DateTimeInterface::ATOM),
            'date_overdue' => $this->dateOverdue?->format(\DateTimeInterface::ATOM),
            'paid' => $this->paid,
            'refunded' => $this->refunded,
            'payment_failure_count' => $this->paymentFailureCount,
            'invoice_items' => $this->invoiceItems,
        ];
    }
}
