<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\DataUsage;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;

/**
 * Data transfer used in the current billing period.
 *
 * `/v2/data_usages`
 *
 * THE ALLOWANCE IS POOLED ACROSS THE ACCOUNT'S SERVERS, and the per-server figures have to be
 * read in that light: each server's `transferGigabytes` is its contribution to the pool, and
 * its usage is measured against the pool rather than against itself. So a single server
 * "over" its own number may be perfectly fine, and the comparison that means something is
 * the total - which is what `total()` and `pooledUsage()` answer.
 *
 * CURRENT PERIOD ONLY. There is no history here: both endpoints are `/current`, and a period
 * that has rolled over is gone. Anything wanting a trend has to record it.
 */
final class DataUsages extends Endpoint
{
    public const COLLECTION = 'data_usages';

    /**
     * One server's usage this period.
     */
    public function forServer(int $serverId): DataUsage
    {
        return $this->apiObject($this->path($serverId), 'data_usage', DataUsage::fromArray(...));
    }

    /**
     * One server's usage, or null when the server is not visible to this token.
     */
    public function findForServer(int $serverId): ?DataUsage
    {
        return $this->apiFind($this->path($serverId), 'data_usage', DataUsage::fromArray(...));
    }

    /**
     * One page of every server's usage.
     *
     * @return Page<DataUsage>
     */
    public function list(int $page = 1, ?int $perPage = null): Page
    {
        return $this->apiPaginate('data_usages/current', self::COLLECTION, DataUsage::fromArray(...), $page, $perPage);
    }

    /**
     * Every server's usage, a page at a time.
     *
     * @return \Generator<int, DataUsage>
     */
    public function each(?int $perPage = null): \Generator
    {
        return $this->apiEach('data_usages/current', self::COLLECTION, DataUsage::fromArray(...), $perPage);
    }

    /**
     * Every server's usage, as a list keyed by server id.
     *
     * @return array<int, DataUsage>
     */
    public function all(?int $perPage = null): array
    {
        $usages = [];

        foreach ($this->each($perPage) as $usage) {
            $usages[$usage->serverId] = $usage;
        }

        return $usages;
    }

    /**
     * The account's total allowance this period, in GB - the pool every server draws on.
     */
    public function total(?int $perPage = null): float
    {
        $total = 0.0;

        foreach ($this->each($perPage) as $usage) {
            $total += $usage->transferGigabytes;
        }

        return $total;
    }

    /**
     * The account's total usage this period, in GB.
     *
     * THE FIGURES ARE ALREADY POOLED, so this sums what each server reports rather than
     * deriving a pool from parts - which is what makes it comparable to total() and not to any
     * one server's number.
     */
    public function pooledUsage(?int $perPage = null): float
    {
        $used = 0.0;

        foreach ($this->each($perPage) as $usage) {
            $used += $usage->currentTransferUsageGigabytes;
        }

        return $used;
    }

    private function path(int $serverId): string
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException(
                sprintf('A server id must be a positive integer; %d was given.', $serverId)
            );
        }

        return 'data_usages/' . $serverId . '/current';
    }
}
