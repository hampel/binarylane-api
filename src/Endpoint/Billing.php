<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\Balance;
use Hampel\BinaryLane\Api\Entity\Invoice;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Balance and invoices.
 *
 * `/v2/customers/my/...` - the specification files these under a `Customers` tag, which is a
 * poor description of four read-only billing endpoints, so this class is named for what it
 * answers. Every path here is fixed to the authenticated account; there is no way to ask
 * about another.
 *
 * `unpaidFailedInvoices()` IS THE ONE WORTH WATCHING. The specification: any invoice that is
 * unpaid and has a failed payment attempt "may block the ability to renew existing services
 * or add new services". That is also what holds a running action indefinitely - see
 * Action::$blockingInvoiceId and ActionBlockedException - so a provisioning job that has
 * mysteriously stopped is worth checking here before anywhere else.
 */
final class Billing extends Endpoint
{
    public const COLLECTION = 'invoices';

    /**
     * What the account owes and what credit it has, in AU$.
     *
     * Not paginated. `Balance::shortfall()` is the number worth alerting on.
     */
    public function balance(): Balance
    {
        return $this->apiObject('customers/my/balance', 'balance', Balance::fromArray(...));
    }

    /**
     * One invoice by id.
     *
     * THE INTEGER ID, not the invoice number a person quotes - see Entity\Invoice.
     */
    public function invoice(int $invoiceId): Invoice
    {
        return $this->apiObject($this->path($invoiceId), 'invoice', Invoice::fromArray(...));
    }

    /**
     * One invoice by id, or null.
     */
    public function findInvoice(int $invoiceId): ?Invoice
    {
        return $this->apiFind($this->path($invoiceId), 'invoice', Invoice::fromArray(...));
    }

    /**
     * One page of the account's invoices.
     *
     * @return Page<Invoice>
     */
    public function invoices(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate(
            'customers/my/invoices',
            self::COLLECTION,
            Invoice::fromArray(...),
            $page,
            $perPage
        );
    }

    /**
     * Every invoice, a page at a time.
     *
     * @return \Generator<int, Invoice>
     */
    public function eachInvoice(?int $perPage = null): \Generator
    {
        return $this->apiEach('customers/my/invoices', self::COLLECTION, Invoice::fromArray(...), $perPage);
    }

    public function countInvoices(): int
    {
        return $this->apiCount('customers/my/invoices', self::COLLECTION);
    }

    /**
     * The invoices that are unpaid AND have a failed payment attempt.
     *
     * THE ONES THAT BLOCK THINGS - see the class note. Not paginated: the response carries the
     * whole list, which on a healthy account is empty.
     *
     * @return list<Invoice>
     */
    public function unpaidFailedInvoices(): array
    {
        return Cast::objects(
            $this->apiGet('customers/my/unpaid-payment-failed-invoices')->requireArray(self::COLLECTION),
            Invoice::fromArray(...)
        );
    }

    /**
     * Whether anything is currently blocking new or renewed services.
     */
    public function isBlocked(): bool
    {
        return $this->unpaidFailedInvoices() !== [];
    }

    private function path(int $invoiceId): string
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException(
                sprintf('An invoice id must be a positive integer; %d was given.', $invoiceId)
            );
        }

        return 'customers/my/invoices/' . $invoiceId;
    }
}
