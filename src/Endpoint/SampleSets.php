<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Entity\SampleSet;
use Hampel\BinaryLane\Api\Enum\DataInterval;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * Performance and usage data for a server.
 *
 * `/v2/samplesets`
 *
 * WHAT YOU GET IS AVERAGES. Each sample set carries one averaged Sample plus two maximums -
 * memory and storage - and nothing else. There is no maximum CPU and no maximum network rate,
 * so a spike shorter than the interval is invisible in everything but those two. Choosing a
 * five-minute interval to "see more detail" does not recover it; it only narrows the window
 * each average covers.
 *
 * THE WINDOW DEFAULTS TO A WEEK EITHER SIDE. `start` defaults to a week back and `end` to a
 * week forward, per the specification - so a call with no window returns rather more than
 * "recent", and pagination matters.
 */
final class SampleSets extends Endpoint
{
    public const COLLECTION = 'sample_sets';

    /**
     * The most recent sample set for a server.
     *
     * The cheap call for a dashboard: one request, one period, no window to choose.
     */
    public function latest(int $serverId, ?DataInterval $interval = null): SampleSet
    {
        return $this->apiObject(
            $this->path($serverId) . '/latest',
            'sample_set',
            SampleSet::fromArray(...),
            $this->filters($interval)
        );
    }

    /**
     * The most recent sample set, or null when the server is not visible to this token.
     */
    public function findLatest(int $serverId, ?DataInterval $interval = null): ?SampleSet
    {
        return $this->apiFind(
            $this->path($serverId) . '/latest',
            'sample_set',
            SampleSet::fromArray(...),
            $this->filters($interval)
        );
    }

    /**
     * One page of a server's sample sets.
     *
     * @param  \DateTimeInterface|null  $start  defaults, at the API's end, to a week back
     * @param  \DateTimeInterface|null  $end  defaults to a week forward
     * @return Page<SampleSet>
     */
    public function list(
        int $serverId,
        int $page = 1,
        ?int $perPage = null,
        ?DataInterval $interval = null,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): Page {
        return $this->apiPaginate(
            $this->path($serverId),
            self::COLLECTION,
            SampleSet::fromArray(...),
            $page,
            $perPage,
            $this->filters($interval, $start, $end)
        );
    }

    /**
     * Every sample set in the window, a page at a time.
     *
     * @return \Generator<int, SampleSet>
     */
    public function each(
        int $serverId,
        ?int $perPage = null,
        ?DataInterval $interval = null,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): \Generator {
        return $this->apiEach(
            $this->path($serverId),
            self::COLLECTION,
            SampleSet::fromArray(...),
            $perPage,
            $this->filters($interval, $start, $end)
        );
    }

    /**
     * Every sample set between two instants, as a list.
     *
     * The window is explicit here rather than defaulted, which is usually what a report wants -
     * see the class note on what "no window" actually means.
     *
     * @return list<SampleSet>
     */
    public function between(
        int $serverId,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?DataInterval $interval = null,
        ?int $perPage = null,
    ): array {
        if ($start > $end) {
            throw new InvalidArgumentException('The start of a sample window must not be after its end.');
        }

        return iterator_to_array($this->each($serverId, $perPage, $interval, $start, $end), false);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function filters(
        ?DataInterval $interval = null,
        ?\DateTimeInterface $start = null,
        ?\DateTimeInterface $end = null,
    ): array {
        $filters = [];

        if ($interval !== null) {
            $filters['data_interval'] = $interval->value;
        }

        if ($start !== null) {
            $filters['start'] = Cast::timestamp($start);
        }

        if ($end !== null) {
            $filters['end'] = Cast::timestamp($end);
        }

        return $filters;
    }

    private function path(int $serverId): string
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException(
                sprintf('A server id must be a positive integer; %d was given.', $serverId)
            );
        }

        return 'samplesets/' . $serverId;
    }
}
