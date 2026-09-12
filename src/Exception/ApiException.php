<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Exception;

use Hampel\BinaryLane\Api\ProblemDetail;
use Psr\Http\Message\ResponseInterface;

/**
 * BinaryLane answered, and the answer was not a success.
 *
 * READ THE STATUS, NOT THE PROSE. The API reports every caller mistake in the same RFC 7807
 * envelope and gives a 400 the same title every time - "One or more validation errors
 * occurred." - so the title is never the thing that tells you what happened. The status is,
 * and the subclasses below are that status made catchable: a rejected value (400), a token
 * that is not a token (401), a token that may not do this (403), no such thing (404).
 *
 * The parsed body is on `problem`, and it is nullable rather than always-present because a
 * 401 on this API carries no body at all - see ProblemDetail.
 */
abstract class ApiException extends BinaryLaneException
{
    /**
     * @param  string  $body  the raw response body, for when it was not JSON at all
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?ProblemDetail $problem = null,
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>|null  $decoded  the decoded body, or null if it was not JSON
     */
    public static function fromResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        ?array $decoded,
        string $body,
    ): self {
        $status = $response->getStatusCode();
        $problem = ProblemDetail::from($decoded);

        $detail = $problem?->describe() ?? '';

        if ($detail === '') {
            $detail = trim($body);
        }

        // A 401 has nothing in it to quote, so the message says what the status means rather
        // than leaving the sentence hanging. This is the failure a new integration meets
        // first and the one where a useless message costs the most time.
        if ($detail === '' && $status === 401) {
            $detail = 'the API token was missing, malformed, expired or revoked (this status carries no body)';
        }

        $message = sprintf(
            'BinaryLane rejected %s %s (HTTP %d)%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail
        );

        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 400 => new ValidationException($message, $status, $problem, $body, $retryAfter),
            $status === 401 => new NotAuthenticatedException($message, $status, $problem, $body, $retryAfter),
            $status === 403 => new NotPermittedException($message, $status, $problem, $body, $retryAfter),
            $status === 404 => new NotFoundException($message, $status, $problem, $body, $retryAfter),
            $status === 429 => new TooManyRequestsException($message, $status, $problem, $body, $retryAfter),
            $status >= 500 => new ServerException($message, $status, $problem, $body, $retryAfter),
            default => new ClientException($message, $status, $problem, $body, $retryAfter),
        };
    }

    /**
     * Every message the API gave, in order, for a caller that wants to show them rather than
     * branch on them. Empty for a status that carried no body.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return $this->problem?->messages() ?? [];
    }

    /**
     * The rejected fields keyed by name, which is the shape a form wants.
     *
     * THE KEYS ARE JSON PROPERTY NAMES - `vpc_id`, `backup_id`, `size` - because they are
     * echoed from the request. Only a 400 populates this.
     *
     * @return array<string, list<string>>
     */
    public function fieldErrors(): array
    {
        return $this->problem->errors ?? [];
    }

    /**
     * Whether the API named this field as a problem.
     */
    public function concerns(string $field): bool
    {
        return $this->problem?->concerns($field) ?? false;
    }

    /**
     * The API's own title for the failure, when it sent one.
     *
     * Worth showing next to the field errors and never instead of them: on a 400 it is
     * always the same sentence.
     */
    public function title(): ?string
    {
        return $this->problem?->title;
    }

    /**
     * `Retry-After`, in seconds, when the response carried one as a plain integer.
     *
     * The header's other legal form is an HTTP date, which is not parsed here - a null means
     * "no usable number", not "no header". The value is captured for every status because
     * nothing in the specification says which ones carry it; a 429 is the only status where
     * acting on it is clearly right, and the exception's own type is how to tell.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));

        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }
}
