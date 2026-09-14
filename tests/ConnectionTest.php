<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use Hampel\BinaryLane\Api\Connection;
use Hampel\BinaryLane\Api\Exception\ClientException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;
use Hampel\BinaryLane\Api\Exception\NotFoundException;
use Hampel\BinaryLane\Api\Exception\NotPermittedException;
use Hampel\BinaryLane\Api\Exception\RequestException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Exception\ServerException;
use Hampel\BinaryLane\Api\Exception\TooManyRequestsException;
use Hampel\BinaryLane\Api\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConnectionTest extends TestCase
{
    private function connection(?RecordingLogger $logger = null): Connection
    {
        return $this->binarylane(logger: $logger)->connection();
    }

    public function testItSendsTheTokenAndAsksForJson(): void
    {
        $this->client->pushJson(200, ['account' => []]);

        $this->connection()->get('account');

        $request = $this->client->lastRequest();

        $this->assertSame('Bearer test-token-000000000000abcd', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testItPostsAJsonBodyWithNoCharsetParameter(): void
    {
        $this->client->pushJson(200, ['action' => ['id' => 1]]);

        $this->connection()->post('servers/1/actions', ['type' => 'power_on']);

        $this->assertSame('POST', $this->sentMethod());
        $this->assertSame('application/json', $this->client->lastRequest()->getHeaderLine('Content-Type'));
        $this->assertSame(['type' => 'power_on'], $this->sentBody());
    }

    public function testItSendsAPatchForAPartialUpdate(): void
    {
        $this->client->pushJson(200, ['vpc' => []]);

        $this->connection()->patch('vpcs/7', ['name' => 'renamed']);

        $this->assertSame('PATCH', $this->sentMethod());
        $this->assertSame(['name' => 'renamed'], $this->sentBody());
    }

    /**
     * Removing servers from a load balancer is a DELETE that carries a body, which is unusual
     * enough to be worth pinning down.
     */
    public function testItSendsABodyOnADeleteWhenGivenOne(): void
    {
        $this->client->pushRaw(204, '');

        $this->connection()->delete('load_balancers/9/servers', [], ['server_ids' => [1, 2]]);

        $this->assertSame('DELETE', $this->sentMethod());
        $this->assertSame(['server_ids' => [1, 2]], $this->sentBody());
    }

    public function testADeleteWithNoPayloadCarriesNoContentTypeHeader(): void
    {
        $this->client->pushRaw(204, '');

        $this->connection()->delete('servers/1');

        $this->assertSame('', $this->client->lastRequest()->getHeaderLine('Content-Type'));
    }

    /**
     * A 204 is what a successful delete looks like on this API - an empty answer, not a
     * malformed one.
     */
    public function testANoContentResponseIsAnEmptySuccess(): void
    {
        $this->client->pushRaw(204, '');

        $response = $this->connection()->delete('servers/1');

        $this->assertSame(204, $response->status);
        $this->assertTrue($response->isEmpty());
        $this->assertSame([], $response->data);
    }

    /**
     * Every server action declares a bodiless 202 alongside its 200. Reading that as a
     * malformed response would turn an accepted action into an exception.
     */
    public function testAnAcceptedResponseWithNoBodyIsAnEmptySuccess(): void
    {
        $this->client->pushRaw(202, '');

        $response = $this->connection()->post('servers/1/actions', ['type' => 'power_on']);

        $this->assertSame(202, $response->status);
        $this->assertTrue($response->isAccepted());
        $this->assertTrue($response->isEmpty());
    }

    /**
     * THE 0.1.0 HOLE. `send()` accepted an empty body on any 2xx, so a proxy's empty 200
     * became an empty ApiResponse and reached the caller as "this account has no servers" -
     * the exact failure the code three lines below it says it exists to prevent.
     *
     * The specification settles it: all 104 of its 200s declare a content schema, and only
     * 202 and 204 are declared bodiless.
     */
    public function testAnEmpty200IsMalformedRatherThanAnEmptyResult(): void
    {
        $this->client->pushRaw(200, '');

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('the body was empty');

        $this->connection()->get('servers');
    }

    public function testAnEmpty200WithAJsonContentTypeIsAlsoMalformed(): void
    {
        $this->client->pushRaw(200, '', ['Content-Type' => 'application/json']);

        $this->expectException(MalformedResponseException::class);

        $this->connection()->get('servers');
    }

    /**
     * The two the specification really does declare bodiless must keep working.
     */
    public function testTheTwoDeliberateBodilessSuccessesStillPass(): void
    {
        $this->client->pushRaw(204, '');
        $this->assertTrue($this->connection()->delete('servers/1')->isEmpty());

        $this->client->pushRaw(202, '');
        $this->assertTrue($this->connection()->post('servers/1/actions', ['type' => 'power_on'])->isAccepted());
    }

    public function testA200WithAnUndecodableBodyIsAFailureRatherThanAnEmptyList(): void
    {
        $this->client->pushRaw(200, '<html><body>Service Unavailable</body></html>', ['Content-Type' => 'text/html']);

        $this->expectException(MalformedResponseException::class);
        $this->expectExceptionMessage('not JSON');

        $this->connection()->get('servers');
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[DataProvider('failureStatuses')]
    public function testItMapsAStatusToItsOwnExceptionType(int $status, string $expected): void
    {
        $this->client->pushJson($status, ['title' => 'Not Found', 'status' => $status]);

        $this->expectException($expected);

        $this->connection()->get('servers/1');
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function failureStatuses(): iterable
    {
        yield '400' => [400, ValidationException::class];
        yield '401' => [401, NotAuthenticatedException::class];
        yield '403' => [403, NotPermittedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '429' => [429, TooManyRequestsException::class];
        yield '500' => [500, ServerException::class];
        yield '418' => [418, ClientException::class];
    }

    /**
     * The 400 is the only status that carries a field map, and it is the reason to catch
     * ValidationException by name.
     */
    public function testAValidationFailureCarriesTheFieldsTheApiNamed(): void
    {
        $this->client->pushJson(400, $this->validationProblem([
            'size' => ['The size is not available in this region.'],
            'image' => ['Unknown image.'],
        ]));

        try {
            $this->connection()->post('servers', ['size' => 'std-min']);

            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue($e->concerns('size'));
            $this->assertFalse($e->concerns('region'));
            $this->assertSame(['The size is not available in this region.'], $e->fieldErrors()['size']);
            $this->assertSame('One or more validation errors occurred.', $e->title());
            $this->assertCount(2, $e->messages());
            $this->assertStringContainsString('size: The size is not available in this region.', $e->getMessage());
        }
    }

    /**
     * A 401 on this API has no body at all, so the message has to be written rather than
     * quoted - otherwise the commonest first failure reports nothing.
     */
    public function testAnUnauthorisedFailureExplainsItselfWithNoBodyToQuote(): void
    {
        $this->client->pushRaw(401, '');

        try {
            $this->connection()->get('account');

            $this->fail('expected a NotAuthenticatedException');
        } catch (NotAuthenticatedException $e) {
            $this->assertNull($e->problem);
            $this->assertSame([], $e->messages());
            $this->assertStringContainsString('missing, malformed, expired or revoked', $e->getMessage());
            $this->assertSame(401, $e->statusCode);
        }
    }

    /**
     * The specification declares no response headers at all, and the live API sends one -
     * which is why ResponseMeta keeps every header rather than naming the ones it expects.
     */
    public function testItKeepsAHeaderTheSpecificationDoesNotDocument(): void
    {
        $this->client->pushJson(200, ['account' => []], ['X-Spec-Version' => '0.40.0']);

        $meta = $this->connection()->get('account')->meta;

        $this->assertSame('0.40.0', $meta->specVersion());
        $this->assertSame('0.40.0', $meta->header('x-spec-version'), 'matched case-insensitively');
        $this->assertArrayHasKey('x-spec-version', $meta->toArray());
    }

    public function testAnAbsentHeaderIsNullRatherThanEmpty(): void
    {
        $this->client->pushJson(200, ['account' => []]);

        $meta = $this->connection()->get('account')->meta;

        $this->assertNull($meta->specVersion());
        $this->assertNull($meta->header('X-Request-Id'));
    }

    /**
     * A path the API does not route answers 404 with no body, despite the specification
     * declaring ProblemDetails for every 404 it documents.
     */
    public function testANotFoundWithNoBodyIsStillANotFound(): void
    {
        $this->client->pushRaw(404, '');

        try {
            $this->connection()->get('no-such-thing');

            $this->fail('expected a NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertNull($e->problem);
            $this->assertSame(404, $e->statusCode);
        }
    }

    public function testItCapturesARetryAfterHeaderWhenItIsAPlainInteger(): void
    {
        $this->client->pushJson(429, ['title' => 'Too many requests'], ['Retry-After' => '30']);

        try {
            $this->connection()->get('servers');

            $this->fail('expected a TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function testItIgnoresARetryAfterGivenAsAnHttpDate(): void
    {
        $this->client->pushJson(429, [], ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']);

        try {
            $this->connection()->get('servers');

            $this->fail('expected a TooManyRequestsException');
        } catch (TooManyRequestsException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    public function testATransportFailureIsNotAnApiFailure(): void
    {
        $this->client->pushThrowable(new TransportFailure(
            $this->binarylane()->connection()->request('GET', 'servers')
        ));

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Could not reach the BinaryLane API');

        $this->connection()->get('servers');
    }

    /**
     * Laravel's StrayRequestException is a plain RuntimeException, and a consumer's test
     * depends on seeing it rather than "could not reach the API".
     */
    public function testSomethingThatIsNotATransportFailurePassesThroughUntouched(): void
    {
        $this->client->pushThrowable(new \RuntimeException('Attempted request to [https://api.binarylane.com.au] without a matching fake.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        $this->connection()->get('servers');
    }

    public function testItFollowsALinkThatPointsAtTheConfiguredApi(): void
    {
        $this->client->pushJson(200, ['servers' => [], 'meta' => ['total' => 0]]);

        $this->connection()->follow('https://api.binarylane.com.au/v2/servers?page=2');

        $this->assertSame('/v2/servers', $this->sentPath());
        $this->assertSame('page=2', $this->sentQuery());
    }

    /**
     * The link comes out of a response body and is requested with the account's token
     * attached. Following one that names another host would send the credential there.
     */
    public function testItRefusesToFollowALinkPointingSomewhereElse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to follow');

        $this->connection()->follow('https://evil.example/v2/servers?page=2');
    }

    /**
     * The refusal lives where every request is built, not only in follow(), so a caller that
     * hands a link to get() instead cannot skip it.
     */
    public function testAnAbsoluteUriToAnotherHostIsRefusedByEveryVerb(): void
    {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
            try {
                $this->connection()->{$verb}('https://evil.example/v2/servers');
                $this->fail($verb . ' should have refused the foreign URI');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing to request', $e->getMessage());
            }
        }

        $this->assertSame([], $this->client->requests);
    }

    public function testAnAbsoluteUriToTheConfiguredApiIsAllowed(): void
    {
        $this->client->pushJson(200, ['servers' => []]);

        $this->connection()->get('https://api.binarylane.com.au/v2/servers?page=2');

        $this->assertSame('/v2/servers', $this->sentPath());
    }

    public function testAnAbsoluteUriOverPlainHttpIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->connection()->get('http://api.binarylane.com.au/v2/servers');
    }

    public function testRefusingALinkMakesNoRequest(): void
    {
        try {
            $this->connection()->follow('https://evil.example/v2/servers');
        } catch (RuntimeException) {
            // asserted on below
        }

        $this->assertSame([], $this->client->requests);
    }
}
