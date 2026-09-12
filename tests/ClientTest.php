<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Config;
use Hampel\BinaryLane\Api\Endpoint\Account;
use Hampel\BinaryLane\Api\Endpoint\Actions;
use Hampel\BinaryLane\Api\Endpoint\Endpoint;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Support\Psr17Discovery;

final class ClientTest extends TestCase
{
    public function testItMemoisesAnEndpoint(): void
    {
        $binarylane = $this->binarylane();

        $this->assertSame($binarylane->actions(), $binarylane->actions());
        $this->assertSame($binarylane->endpoint(Actions::class), $binarylane->actions());
    }

    /**
     * The guard takes a plain string, because a class name that is not an endpoint is
     * unrepresentable in the generic signature - see Endpoint::assertConstructible().
     */
    public function testItRefusesAClassThatIsNotAnEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('concrete subclass');

        Endpoint::assertConstructible(Config::class);
    }

    public function testItRefusesAClassThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Endpoint::assertConstructible('No\\Such\\Endpoint');
    }

    /**
     * Endpoint itself satisfies `class-string<Endpoint>` and cannot be constructed, so
     * nothing but this guard stands between it and a fatal error.
     */
    public function testItRefusesTheAbstractBaseClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->binarylane()->endpoint(Endpoint::class);
    }

    /**
     * The documented extension point: a class this package has never heard of.
     */
    public function testItConstructsAnEndpointSubclassItHasNeverHeardOf(): void
    {
        $this->client->pushJson(200, ['servers' => [], 'meta' => ['total' => 0]]);

        $endpoint = $this->binarylane()->endpoint(ExampleEndpoint::class);

        $this->assertInstanceOf(ExampleEndpoint::class, $endpoint);
        $this->assertSame(0, $endpoint->howMany());
    }

    public function testWithCredentialKeepsTheTransportAndDropsTheEndpoints(): void
    {
        $binarylane = $this->binarylane();
        $first = $binarylane->actions();

        $other = $binarylane->withCredential(new ApiToken('another-token-0000000000wxyz'));

        $this->assertNotSame($binarylane, $other);
        $this->assertNotSame($first, $other->actions());
        $this->assertSame(
            $binarylane->connection()->client(),
            $other->connection()->client(),
            'the transport is shared; the credential is not'
        );

        $this->client->pushJson(200, ['account' => []]);
        $other->account()->get();

        $this->assertSame(
            'Bearer another-token-0000000000wxyz',
            $this->client->lastRequest()->getHeaderLine('Authorization')
        );
    }

    public function testWithConfigKeepsTheCredential(): void
    {
        $binarylane = $this->binarylane();
        $other = $binarylane->withConfig(new Config('http://127.0.0.1:9999'));

        $this->client->pushJson(200, ['account' => []]);
        $other->account()->get();

        $this->assertSame('127.0.0.1', $this->client->lastRequest()->getUri()->getHost());
        $this->assertSame(
            'Bearer test-token-000000000000abcd',
            $this->client->lastRequest()->getHeaderLine('Authorization')
        );
    }

    public function testTheNamedAccessorsReachTheRightEndpoints(): void
    {
        $binarylane = $this->binarylane();

        $this->assertInstanceOf(Account::class, $binarylane->account());
        $this->assertInstanceOf(Actions::class, $binarylane->actions());
    }

    public function testItFindsAPsr17FactoryWhenNoneIsGiven(): void
    {
        $binarylane = \Hampel\BinaryLane\Api\Client::withToken('a-token-000000000000abcd', $this->client);

        $this->client->pushJson(200, ['account' => []]);
        $binarylane->account()->get();

        $this->assertSame('/v2/account', $this->sentPath());
    }

    public function testPsr17DiscoveryExplainsItselfWhenItFindsNothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No PSR-17 factory');

        Psr17Discovery::from([['Not\A\Real\Factory', 'Not\A\Real\Factory']]);
    }

    /**
     * A class that exists but is not a factory is skipped rather than returned - which is what
     * keeps discovery from handing back something that will fatal on first use.
     */
    public function testPsr17DiscoverySkipsAClassThatIsNotAFactory(): void
    {
        $this->expectException(RuntimeException::class);

        Psr17Discovery::from([[Config::class, Config::class]]);
    }

    public function testTheTokenIsNotPrintable(): void
    {
        $token = new ApiToken('abcdefghijklmnopqrstuvwxyz');

        $this->assertStringNotContainsString('abcdefghij', (string) $token);
        $this->assertStringNotContainsString('abcdefghij', print_r($token, true));
        $this->assertStringContainsString('ending wxyz', $token->describe());
        $this->assertStringContainsString('26 characters', $token->describe());
    }

    public function testAShortTokenDoesNotLeakAMeaningfulFractionOfItself(): void
    {
        $this->assertSame('a BinaryLane API token of 5 characters', (new ApiToken('short'))->describe());
    }

    public function testAnEmptyTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiToken('   ');
    }
}

/**
 * Stands in for an endpoint shipped by somebody else - the extension point Endpoint
 * documents.
 */
final class ExampleEndpoint extends Endpoint
{
    public function howMany(): int
    {
        return $this->apiCount('servers', 'servers');
    }
}
