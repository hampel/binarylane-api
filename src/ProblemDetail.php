<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The body of a failed request, parsed.
 *
 * BinaryLane reports failures as RFC 7807 problem details - the ASP.NET Core shape, because
 * that is what the API is built on:
 *
 *     {"type": "...", "title": "One or more validation errors occurred.",
 *      "status": 400, "detail": null, "instance": null,
 *      "errors": {"size": ["The size 'std-min' is not available in this region."]}}
 *
 * TWO SHAPES, NOT ONE, AND THE SPECIFICATION NAMES THEM SEPARATELY. A 400 answers with
 * `ValidationProblemDetails`, which is the above WITH `errors`; a 403 and a 404 answer with
 * `ProblemDetails`, which is the same object WITHOUT it. One class covers both, with an
 * empty `errors` for the second - the alternative is a consumer type-checking the exception's
 * payload before it can read a title.
 *
 * A 401 CARRIES NO BODY AT ALL. Every operation in the specification declares `401
 * Unauthorized` with no content schema, alone among the failure statuses. So for the one
 * failure a new integration hits first, there is nothing here to read: `title` is null,
 * `errors` is empty, and the only information is the status. That is why
 * NotAuthenticatedException writes its own message rather than quoting the API's.
 *
 * `additionalProperties` is open in the specification, so a body may carry keys this class
 * does not name. They are kept in `extra` rather than dropped - a field added to the API
 * after this package was written is worth being able to see without waiting for a release.
 */
final class ProblemDetail implements \JsonSerializable
{
    /**
     * The keys RFC 7807 defines, which this class reads into named properties. Anything else
     * in the body lands in `extra`.
     *
     * @var list<string>
     */
    public const RESERVED = ['type', 'title', 'status', 'detail', 'instance', 'errors'];

    /**
     * @param  array<string, list<string>>  $errors  the API's `errors` map: one entry per
     *                                               rejected field, each a list of messages.
     *                                               Empty for every status but 400
     * @param  array<string, mixed>  $extra  anything in the body that RFC 7807 does not name
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $type = null,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
        public readonly ?string $instance = null,
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
    }

    /**
     * Parse a decoded response body, or answer null when there was nothing to parse.
     *
     * Defensive about every level, because this runs on the failure path: the thing that
     * answered may not have been BinaryLane at all, and an exception raised while building an
     * exception loses the original failure.
     *
     * @param  array<mixed>|null  $decoded
     */
    public static function from(?array $decoded): ?self
    {
        if ($decoded === null || $decoded === []) {
            return null;
        }

        $errors = [];

        foreach (Cast::array($decoded['errors'] ?? null) as $field => $messages) {
            if (!is_string($field)) {
                continue;
            }

            // A value that is not a list of strings is still worth surfacing rather than
            // dropping: `{"errors": {"size": "not available"}}` is not the documented shape,
            // but a consumer showing it to a user wants the string more than it wants
            // consistency.
            $messages = is_array($messages) ? Cast::strings($messages) : Cast::strings([$messages]);

            if ($messages !== []) {
                $errors[$field] = $messages;
            }
        }

        $extra = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && !in_array($key, self::RESERVED, true)) {
                $extra[$key] = $value;
            }
        }

        return new self(
            Cast::string($decoded['title'] ?? null),
            Cast::string($decoded['type'] ?? null),
            Cast::int($decoded['status'] ?? null),
            Cast::string($decoded['detail'] ?? null),
            Cast::string($decoded['instance'] ?? null),
            $errors,
            $extra,
        );
    }

    /**
     * Every message across every field, in order, for a caller that wants to show them rather
     * than branch on them.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->errors as $field) {
            foreach ($field as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Whether the API named this field as a problem.
     *
     * THE NAMES ARE THE JSON PROPERTY NAMES, not the argument names this package uses:
     * `vpc_id`, not `$vpcId`. They come back exactly as they were sent.
     */
    public function concerns(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * The messages for one field, or an empty list when it was not named.
     *
     * @return list<string>
     */
    public function for(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * A single line describing the failure, for an exception message or a log.
     *
     * The field messages are preferred over the title when there are any, because the title
     * of a 400 is always the same sentence - "One or more validation errors occurred." -
     * and says nothing about which value was wrong.
     */
    public function describe(): string
    {
        $messages = $this->messages();

        if ($messages !== []) {
            $described = [];

            foreach ($this->errors as $field => $fieldMessages) {
                foreach ($fieldMessages as $message) {
                    $described[] = $field . ': ' . $message;
                }
            }

            return implode('; ', $described);
        }

        foreach ([$this->detail, $this->title] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    /**
     * Whether there was anything in the body worth reporting.
     *
     * False for the empty problem detail a 401 produces, which is the case worth checking:
     * there is no point printing "the API said:" and then nothing.
     */
    public function isEmpty(): bool
    {
        return $this->describe() === '' && $this->errors === [] && $this->extra === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'errors' => $this->errors === [] ? null : $this->errors,
        ], static fn (mixed $value): bool => $value !== null) + $this->extra;
    }
}
