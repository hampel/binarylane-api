<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Exception\ActionBlockedException;
use Hampel\BinaryLane\Api\Exception\ActionFailedException;
use Hampel\BinaryLane\Api\Exception\ActionTimedOutException;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Exception\RequestException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Exception\ServerException;

/**
 * The client does not log a failure it raises. Requests are logged at `debug`, and that is all.
 *
 * Whether an exception is a failure is decided by whoever catches it, and the client catches
 * some of its own: find() turns a 404 into null, and checkRunning() turns an errored action
 * into "no". Logged at `error` before the throw, both reported an ordinary answer as a fault -
 * and every failure a caller did log arrived twice. 0.2.0 did both, for some exception types
 * and not others, so a caller could not even skip the ones already recorded.
 *
 * Each case here asserts nothing reached a level above `debug`, which is what would reach an
 * alerting channel.
 */
final class LoggingTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertRaisedWithoutLogging(string $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('expected ' . $expected);
        } catch (ExceptionInterface $e) {
            $this->assertInstanceOf($expected, $e);
        }

        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function testARejectedRequestIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(500, ['title' => 'Internal Server Error']);

        $this->assertRaisedWithoutLogging(
            ServerException::class,
            fn () => $this->binarylane(logger: $this->logger)->servers()->get(1)
        );
    }

    public function testATransportFailureIsRaisedAndNotLogged(): void
    {
        $this->client->pushThrowable(new TransportFailure(
            $this->binarylane()->connection()->request('GET', 'servers')
        ));

        $this->assertRaisedWithoutLogging(
            RequestException::class,
            fn () => $this->binarylane(logger: $this->logger)->servers()->get(1)
        );
    }

    public function testABodyThatIsNotJsonIsRaisedAndNotLogged(): void
    {
        $this->client->pushRaw(200, '<html>Service Unavailable</html>', ['Content-Type' => 'text/html']);

        $this->assertRaisedWithoutLogging(
            MalformedResponseException::class,
            fn () => $this->binarylane(logger: $this->logger)->servers()->get(1)
        );
    }

    public function testAMissingEnvelopeKeyIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(200, ['unexpected' => true]);

        $this->assertRaisedWithoutLogging(
            MalformedResponseException::class,
            fn () => $this->binarylane(logger: $this->logger)->servers()->get(1)
        );
    }

    public function testRefusingAForeignLinkIsRaisedAndNotLogged(): void
    {
        $this->assertRaisedWithoutLogging(
            RuntimeException::class,
            fn () => $this->binarylane(logger: $this->logger)->connection()->follow('https://evil.example/v2/servers')
        );
    }

    public function testAFailedActionIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(200, $this->action(7, 'errored'));

        $this->assertRaisedWithoutLogging(
            ActionFailedException::class,
            fn () => $this->binarylane(logger: $this->logger)->actions()->await(7)
        );
    }

    public function testABlockedActionIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress', ['blocking_invoice_id' => 9182]));

        $this->assertRaisedWithoutLogging(
            ActionBlockedException::class,
            fn () => $this->binarylane(logger: $this->logger)->actions()->await(7)
        );
    }

    public function testATimedOutActionIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(200, $this->action(7, 'in-progress'));

        $this->assertRaisedWithoutLogging(
            ActionTimedOutException::class,
            fn () => $this->binarylane(logger: $this->logger)->actions()->await(7, timeout: 0)
        );
    }

    public function testAnActionWithAnUnclassifiableStatusIsRaisedAndNotLogged(): void
    {
        $this->client->pushJson(200, $this->action(7, 'something-new'));

        $this->assertRaisedWithoutLogging(
            MalformedResponseException::class,
            fn () => $this->binarylane(logger: $this->logger)->actions()->await(7)
        );
    }

    /**
     * The case that made this a defect rather than a preference: "there is no such server" is
     * the answer find() exists to give, and 0.2.0 logged it as an API error.
     */
    public function testFindingNothingIsNotLoggedAsAnError(): void
    {
        $this->client->pushJson(404, ['title' => 'Not Found']);

        $this->assertNull($this->binarylane(logger: $this->logger)->servers()->find(999));
        $this->assertSame([], $this->logger->aboveDebug());
    }

    /**
     * And the other: a stopped server answers "is it running?" by erroring the action, so
     * 0.2.0 logged "BinaryLane action failed" every time the answer was no.
     */
    public function testAServerThatIsNotRunningIsNotLoggedAsAFailure(): void
    {
        $asked = $this->action(5, 'in-progress', ['type' => 'is_running']);

        $this->client
            ->pushJson(200, $asked)
            ->pushJson(200, $this->action(5, 'errored', ['type' => 'is_running']));

        $this->assertFalse($this->binarylane(logger: $this->logger)->serverActions()->checkRunning(1234));
        $this->assertSame([], $this->logger->aboveDebug());
    }

    public function testRequestsAreStillLoggedAtDebug(): void
    {
        $this->client->pushJson(404, ['title' => 'Not Found']);

        $this->binarylane(logger: $this->logger)->servers()->find(999);

        $this->assertNotNull($this->logger->contextFor('BinaryLane API request'));
    }

    public function testNotFoundIsStillRaisedByGet(): void
    {
        $this->client->pushJson(404, ['title' => 'Not Found']);

        $this->assertRaisedWithoutLogging(
            NotFoundException::class,
            fn () => $this->binarylane(logger: $this->logger)->servers()->get(999)
        );
    }
}
