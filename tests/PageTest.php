<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Result\ApiResponse;
use Hampel\BinaryLane\Api\Result\Page;
use Hampel\BinaryLane\Api\Result\PageLinks;
use Hampel\BinaryLane\Api\Result\ResponseMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class PageTest extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function response(array $body): ApiResponse
    {
        return new ApiResponse($body, 200, new ResponseMeta());
    }

    public function testItReadsTheItemsTheTotalAndTheLinks(): void
    {
        $page = Page::fromResponse(
            $this->response([
                'servers' => [['id' => 1], ['id' => 2]],
                'meta' => ['total' => 57],
                'links' => ['pages' => [
                    'next' => 'https://api.binarylane.com.au/v2/servers?page=2&per_page=2',
                    'last' => 'https://api.binarylane.com.au/v2/servers?page=29&per_page=2',
                ]],
            ]),
            'servers',
            static fn (array $row): mixed => $row['id'] ?? null,
            1
        );

        $this->assertSame([1, 2], iterator_to_array($page));
        $this->assertCount(2, $page);
        $this->assertSame(57, $page->total, 'total is across every page, not this one');
        $this->assertTrue($page->hasMore());
        $this->assertSame(29, $page->links->lastPage());
        $this->assertSame(2, $page->links->nextPage());
    }

    /**
     * The whole `links` object is absent when there is only one page, which must read as "no
     * more" rather than as a missing field.
     */
    public function testACollectionWithOnePageCarriesNoLinksAtAll(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [['id' => 1]], 'meta' => ['total' => 1]]),
            'servers',
            static fn (array $row): mixed => $row['id'] ?? null
        );

        $this->assertFalse($page->hasMore());
        $this->assertNull($page->nextUri());
        $this->assertTrue($page->links->isEmpty());
        $this->assertNull($page->links->lastPage());
    }

    public function testAnEmptyCollectionIsNotAFailure(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [], 'meta' => ['total' => 0]]),
            'servers',
            static fn (array $row): mixed => $row
        );

        $this->assertTrue($page->isEmpty());
        $this->assertSame(0, $page->total);
        $this->assertNull($page->first());
    }

    public function testItSkipsAnItemThatIsNotAnObject(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [['id' => 1], 'nonsense', null, ['id' => 2]]]),
            'servers',
            static fn (array $row): mixed => $row['id'] ?? null
        );

        $this->assertSame([1, 2], iterator_to_array($page));
    }

    public function testItFallsBackToCountingWhenThereIsNoMeta(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [['id' => 1], ['id' => 2]]]),
            'servers',
            static fn (array $row): mixed => $row
        );

        $this->assertSame(2, $page->total);
    }

    public function testFirstReturnsTheFirstItem(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [['id' => 9], ['id' => 10]]]),
            'servers',
            static fn (array $row): mixed => $row['id'] ?? null
        );

        $this->assertSame(9, $page->first());
    }

    #[DataProvider('perPages')]
    public function testItRefusesAPerPageTheApiWouldRefuse(int $perPage, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        Page::assertValidPerPage($perPage);

        $this->assertTrue($valid);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function perPages(): iterable
    {
        yield 'zero means count only, and is legal' => [0, true];
        yield 'one' => [1, true];
        yield 'the maximum' => [200, true];
        yield 'above the maximum' => [201, false];
        yield 'negative' => [-1, false];
    }

    public function testPageLinksReadsThePageNumberOutOfALink(): void
    {
        $this->assertSame(4, PageLinks::pageOf('https://api.binarylane.com.au/v2/servers?page=4&per_page=20'));
        $this->assertNull(PageLinks::pageOf('https://api.binarylane.com.au/v2/servers'));
        $this->assertNull(PageLinks::pageOf('https://api.binarylane.com.au/v2/servers?page=0'));
        $this->assertNull(PageLinks::pageOf(null));
    }

    public function testPageLinksIgnoresAnEmptyLink(): void
    {
        $links = PageLinks::from(['pages' => ['next' => '', 'prev' => null, 'first' => '  ']]);

        $this->assertTrue($links->isEmpty());
        $this->assertFalse($links->hasNext());
        $this->assertFalse($links->hasPrev());
    }

    public function testPageLinksSurvivesABodyWithNoLinksKey(): void
    {
        $this->assertTrue(PageLinks::from(null)->isEmpty());
        $this->assertTrue(PageLinks::from('nonsense')->isEmpty());
        $this->assertTrue(PageLinks::from([])->isEmpty());
    }

    public function testItSerialisesToItsItems(): void
    {
        $page = Page::fromResponse(
            $this->response(['servers' => [['id' => 1]]]),
            'servers',
            static fn (array $row): mixed => $row['id'] ?? null
        );

        $this->assertSame('[1]', json_encode($page, JSON_THROW_ON_ERROR));
    }
}
