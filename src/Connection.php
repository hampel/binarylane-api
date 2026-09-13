<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api;

use Hampel\BinaryLane\Api\Authentication\Authentication;
use Hampel\BinaryLane\Api\Exception\ApiException;
use Hampel\BinaryLane\Api\Exception\MalformedResponseException;
use Hampel\BinaryLane\Api\Exception\RequestException;
use Hampel\BinaryLane\Api\Exception\RuntimeException;
use Hampel\BinaryLane\Api\Result\ApiResponse;
use Hampel\BinaryLane\Api\Result\ResponseMeta;
use Hampel\BinaryLane\Api\Support\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one, which is
 * the whole point of the package: a host application with its own HTTP stack - a
 * proxy-aware, SSRF-guarded client that all outbound requests are required to go through -
 * implements sendRequest() over it and shares this code, instead of writing a second API
 * client because ours hard-coded the wrong library. It is also what lets a Laravel
 * integration route this traffic through `Http::fake()`.
 *
 * A PSR-18 client does not throw on an HTTP status, only on a transport failure, so the two
 * failure modes stay cleanly separated here.
 *
 * This class is the extension point of last resort. Anything the endpoints do not wrap can
 * be called through it directly:
 *
 *     $binarylane->connection()->get('servers')->collection('servers');
 */
final class Connection
{
    /**
     * Written without a charset parameter, and that is worth a note because it looks like an
     * omission.
     *
     * BinaryLane itself does not care - it parses the body as JSON either way. The
     * consumer's test suite does. `Illuminate\Http\Client\Request::isJson()` is a substring
     * test and survives a parameter, but the same class's `isForm()` is an exact match, and a
     * package that writes its content types loosely in one place tends to write them loosely
     * in both. The narrow form is also what every recorded fixture of this API contains, so a
     * consumer matching on the header sees what they recorded.
     */
    public const JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * The injected transport, exposed rather than hidden because a caller assembling
     * something this class does not cover needs the same client and the same factories to do
     * it, rather than reaching for an HTTP library of its own.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->send($this->request('GET', $path, $query));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function post(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('POST', $path, $query), $payload));
    }

    /**
     * A PUT on this API is a FULL replacement, and that is not a formality.
     *
     * `PUT /v2/vpcs/{id}` requires every field the VPC has and resets anything omitted;
     * `PATCH` on the same path is the partial one. The specification declares both for
     * exactly that reason, and the VPC endpoint is where it bites - a PUT built from three
     * changed fields removes the route entries.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function put(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('PUT', $path, $query), $payload));
    }

    /**
     * The partial update, declared on one path in the whole specification - see put().
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function patch(string $path, array $payload = [], array $query = []): ApiResponse
    {
        return $this->send($this->withJson($this->request('PATCH', $path, $query), $payload));
    }

    /**
     * BinaryLane answers a successful delete with a 204 and no body.
     *
     * A FEW DELETES CARRY A JSON BODY GOING OUT. Removing servers or forwarding rules from a
     * load balancer is a DELETE with a payload describing what to remove, which is unusual
     * enough that several HTTP clients make it awkward - hence the payload argument here.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $query
     */
    public function delete(string $path, array $query = [], array $payload = []): ApiResponse
    {
        $request = $this->request('DELETE', $path, $query);

        return $this->send($payload === [] ? $request : $this->withJson($request, $payload));
    }

    /**
     * Follow a URI the API itself produced - a `links.pages.next`, an action's `href`.
     *
     * REFUSED IF IT DOES NOT POINT AT THE CONFIGURED API. The URI comes out of a response
     * body and is requested with the account's bearer token attached, so following one
     * blindly is the shape of an SSRF: a body naming another host would send the credential
     * there. Nothing in the specification suggests BinaryLane would ever emit such a link,
     * which is exactly why a client that followed anything would never find out.
     */
    public function follow(string $uri): ApiResponse
    {
        if (!$this->config->ownsUri($uri)) {
            $this->logger->error('BinaryLane API returned a link pointing somewhere else', [
                'uri' => $uri,
                'expected_host' => $this->config->host(),
            ]);

            throw new RuntimeException(sprintf(
                'Refusing to follow "%s": the BinaryLane API is configured as %s, and a link '
                    . 'out of a response body is requested with the API token attached.',
                $uri,
                $this->config->baseUri
            ));
        }

        return $this->send($this->request('GET', $uri));
    }

    /**
     * Build a request without sending it, for a caller assembling something this class does
     * not cover. The credential and the Accept header are already applied.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function request(string $method, string $path, array $query = []): RequestInterface
    {
        $request = $this->requestFactory
            ->createRequest($method, $this->config->resolve($path, $query))
            ->withHeader('Accept', self::JSON_CONTENT_TYPE);

        return $this->authentication->applyTo($request);
    }

    /**
     * Attach a JSON body to a request built elsewhere.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withJson(RequestInterface $request, array $payload): RequestInterface
    {
        return $request
            ->withHeader('Content-Type', self::JSON_CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream(Json::encode($payload)));
    }

    /**
     * Send a request that was built elsewhere, with this connection's error handling.
     */
    public function send(RequestInterface $request): ApiResponse
    {
        $response = $this->dispatch($request);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = Json::decode($body);
        $meta = ResponseMeta::fromResponse($response);

        if ($status >= 200 && $status < 300) {
            if ($decoded !== null) {
                return new ApiResponse($decoded, $status, $meta);
            }

            // THE TWO BODILESS SUCCESSES THIS API SENDS ON PURPOSE, AND ONLY THOSE TWO. A
            // 204 is a completed delete. A 202 is a server action the API took and has not
            // reported on - every one of the forty-odd action operations declares it,
            // alongside the 200 that carries the action. Neither is malformed.
            //
            // The status list is exhaustive rather than cautious: every one of the 104 200s
            // in the specification declares a content schema, so an empty 200 is nobody's
            // documented behaviour. 0.1.0 also accepted `trim($body) === ''` on any status,
            // which let a proxy's empty 200 through as an empty result - the exact failure
            // the comment below says this code exists to prevent, three lines above it.
            if ($status === 204 || $status === 202) {
                return new ApiResponse([], $status, $meta);
            }

            // A 2xx that did not decode is not an empty answer, it is somebody else's answer -
            // a maintenance page, a proxy error document, a truncated body. Read as [] it
            // would reach the caller as "this account has no servers", which is the failure
            // worth being loud about on an API that cancels things.
            $this->logger->error('BinaryLane API answered success with a body that is not JSON', [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'status' => $status,
                'content_type' => $response->getHeaderLine('Content-Type'),
            ]);

            throw MalformedResponseException::forResponse(
                $request->getMethod(),
                (string) $request->getUri(),
                $response,
                $body
            );
        }

        $this->logger->error('BinaryLane API error response', [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'status' => $status,
            'body' => $decoded ?? $body,
        ]);

        throw ApiException::fromResponse(
            $request->getMethod(),
            (string) $request->getUri(),
            $response,
            $decoded,
            $body
        );
    }

    /**
     * Log it, send it, and keep a transport failure distinct from an HTTP status. A PSR-18
     * client throws only for the former, which is what makes that separation free.
     *
     * The catch is ClientExceptionInterface and not \Throwable, deliberately. Anything else a
     * client throws is not a transport failure and must not be dressed as one: Laravel's
     * StrayRequestException, raised by `Http::preventStrayRequests()` when a request escapes
     * the fakes, is a plain RuntimeException, and it reaches the consumer's test naming the
     * URL only because it passes through here untouched. Widened, it would arrive as "could
     * not reach the BinaryLane API", which is the wrong diagnosis in the one place a wrong
     * diagnosis costs most.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = (string) $request->getUri();

        $this->logger->debug('BinaryLane API request', [
            'method' => $method,
            'uri' => $uri,
        ]);

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('BinaryLane API request failed', [
                'method' => $method,
                'uri' => $uri,
                'error' => $e->getMessage(),
            ]);

            throw RequestException::for($method, $uri, $e);
        }
    }
}
