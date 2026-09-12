<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Authentication\Authentication;
use Hampel\BinaryLane\Api\Client;
use Hampel\BinaryLane\Api\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function binarylane(
        ?Config $config = null,
        ?LoggerInterface $logger = null,
        ?Authentication $authentication = null,
    ): Client {
        $factory = new HttpFactory();

        return new Client(
            $config ?? new Config(),
            $authentication ?? new ApiToken('test-token-000000000000abcd'),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger()
        );
    }

    /**
     * The body of the last request the stub client was given, decoded.
     *
     * @return array<mixed>
     */
    protected function sentBody(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The path of the last request, without the host - what an assertion about routing
     * actually cares about.
     */
    protected function sentPath(): string
    {
        return $this->client->lastRequest()->getUri()->getPath();
    }

    protected function sentQuery(): string
    {
        return $this->client->lastRequest()->getUri()->getQuery();
    }

    protected function sentMethod(): string
    {
        return $this->client->lastRequest()->getMethod();
    }

    /**
     * A collection envelope in the shape every list endpoint on this API answers with.
     *
     * `links` is omitted entirely when there is no next page, which is what the API does -
     * see PageLinks.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function collection(array $items, string $key, ?int $total = null, ?string $next = null): array
    {
        $body = [
            $key => $items,
            'meta' => ['total' => $total ?? count($items)],
        ];

        if ($next !== null) {
            $body['links'] = ['pages' => ['next' => $next, 'last' => $next]];
        }

        return $body;
    }

    /**
     * A 400's body: RFC 7807 with the `errors` map only a 400 carries.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    protected function validationProblem(array $errors, string $title = 'One or more validation errors occurred.'): array
    {
        return [
            'type' => 'https://tools.ietf.org/html/rfc9110#section-15.5.1',
            'title' => $title,
            'status' => 400,
            'errors' => $errors,
        ];
    }

    /**
     * A 403 or 404 body: the same envelope without `errors`.
     *
     * @return array<string, mixed>
     */
    protected function problem(string $title, int $status = 404, ?string $detail = null): array
    {
        return array_filter([
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * An action body, in the envelope every mutation answers with.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function action(int $id = 1, string $status = 'completed', array $overrides = []): array
    {
        return ['action' => $overrides + [
            'id' => $id,
            'status' => $status,
            'type' => 'power_on',
            'started_at' => '2026-09-12T01:00:00Z',
            'completed_at' => $status === 'in-progress' ? null : '2026-09-12T01:00:30Z',
            'resource_type' => 'server',
            'resource_id' => 1234,
            'region_slug' => 'syd',
            'title' => 'Power On',
            'reason' => 'Requested by user',
            'progress' => [
                'percent_complete' => $status === 'in-progress' ? 50 : 100,
                'current_step' => $status === 'in-progress' ? 'Starting server' : null,
                'current_step_detail' => null,
                'completed_steps' => [],
            ],
        ]];
    }
}
