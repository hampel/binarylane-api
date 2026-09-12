<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Config;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ConfigTest extends BaseTestCase
{
    public function testItDefaultsToBinaryLanesOwnApi(): void
    {
        $config = new Config();

        $this->assertSame('https://api.binarylane.com.au', $config->baseUri);
        $this->assertSame('api.binarylane.com.au', $config->host());
        $this->assertSame('v2', $config->version);
        $this->assertNull($config->perPage);
    }

    public function testItBuildsAnAbsoluteUriFromARelativePath(): void
    {
        $this->assertSame(
            'https://api.binarylane.com.au/v2/servers/1234/actions',
            (new Config())->resolve('servers/1234/actions')
        );
    }

    public function testItAcceptsAPathWrittenWithALeadingSlash(): void
    {
        $this->assertSame(
            'https://api.binarylane.com.au/v2/servers',
            (new Config())->resolve('/servers')
        );
    }

    /**
     * A path copied straight out of BinaryLane's documentation carries `/v2`, and doubling it
     * would 404 on everything.
     */
    public function testItDoesNotDoubleTheVersionSegment(): void
    {
        $config = new Config();

        $this->assertSame('https://api.binarylane.com.au/v2/servers', $config->resolve('/v2/servers'));
        $this->assertSame('https://api.binarylane.com.au/v2/servers', $config->resolve('v2/servers'));
        $this->assertSame('https://api.binarylane.com.au/v2', $config->resolve('v2'));
    }

    /**
     * A URL the API produced - a next-page link, an action href - is fed back in as-is.
     */
    public function testItPassesAnAbsoluteUriThrough(): void
    {
        $uri = 'https://api.binarylane.com.au/v2/servers?page=2&per_page=20';

        $this->assertSame($uri, (new Config())->resolve($uri));
    }

    public function testItAppendsQueryParameters(): void
    {
        $this->assertSame(
            'https://api.binarylane.com.au/v2/servers?page=2&per_page=50',
            (new Config())->resolve('servers', ['page' => 2, 'per_page' => 50])
        );
    }

    public function testItDropsNullQueryParametersButKeepsAZero(): void
    {
        $uri = (new Config())->resolve('servers', ['hostname' => null, 'per_page' => 0]);

        $this->assertSame('https://api.binarylane.com.au/v2/servers?per_page=0', $uri);
    }

    public function testItMergesQueryParametersIntoAUriThatAlreadyHasSome(): void
    {
        $this->assertSame(
            'https://api.binarylane.com.au/v2/servers?page=2&per_page=50',
            (new Config())->resolve('https://api.binarylane.com.au/v2/servers?page=2', ['per_page' => 50])
        );
    }

    public function testItAcceptsALocalBaseUriForAFixtureServer(): void
    {
        $config = new Config('http://127.0.0.1:8080');

        $this->assertSame('http://127.0.0.1:8080/v2/servers', $config->resolve('servers'));
        $this->assertSame('127.0.0.1', $config->host());
    }

    public function testItTrimsATrailingSlashFromTheBaseUri(): void
    {
        $this->assertSame('https://example.test', (new Config('https://example.test/'))->baseUri);
    }

    public function testItRefusesABaseUriWithNoScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        new Config('api.binarylane.com.au');
    }

    public function testItRefusesAnEmptyBaseUri(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('   ');
    }

    public function testItRefusesAVersionThatIsNotASingleSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('single URL segment');

        new Config(version: 'v2/beta');
    }

    public function testItRefusesAPerPageTheApiWouldRefuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(perPage: 500);
    }

    /**
     * Zero is legal: it is the documented "tell me the total and send no items" request.
     */
    public function testItAcceptsAPerPageOfZero(): void
    {
        $this->assertSame(0, (new Config(perPage: 0))->perPage);
    }

    #[DataProvider('ownedUris')]
    public function testItRecognisesItsOwnUris(string $uri, bool $owned): void
    {
        $this->assertSame($owned, (new Config())->ownsUri($uri));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ownedUris(): iterable
    {
        yield 'the API itself' => ['https://api.binarylane.com.au/v2/servers?page=2', true];
        yield 'case-insensitive host' => ['https://API.BinaryLane.com.au/v2/servers', true];
        yield 'another host entirely' => ['https://evil.example/v2/servers', false];
        yield 'a lookalike subdomain' => ['https://api.binarylane.com.au.evil.example/v2', false];
        yield 'downgraded to http' => ['http://api.binarylane.com.au/v2/servers', false];
        yield 'a relative path' => ['/v2/servers', false];
        yield 'nonsense' => ['not a uri at all', false];
    }
}
