<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Authentication;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

/**
 * A BinaryLane API token, presented as a bearer credential.
 *
 * THE TOKEN IS NOT SCOPED AND DOES NOT EXPIRE. There is one kind of credential on this API
 * and it can do everything the account can do - create servers, cancel them, change DNS,
 * read invoices. There is no read-only token to hand to a monitoring job, so a token given
 * to anything is a token that could cancel a server, and that is a fact about the API rather
 * than an omission in this package.
 *
 * The practical consequence is that the guards belong on YOUR side: a job that only reads
 * should call only the methods that read, and anything driving the destructive server
 * actions should be separated from anything that is not.
 *
 * The token is not printable from here: __toString(), var_dump() and a stack trace all show
 * the description rather than the value. That is deliberate and is the main thing this class
 * does beyond setting a header - a credential in a transcript is a credential that has to be
 * rotated, and this one cannot be narrowed first.
 */
final class ApiToken implements Authentication
{
    private readonly string $token;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('A BinaryLane API token is required.');
        }

        $this->token = $token;
    }

    public function applyTo(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('Authorization', 'Bearer ' . $this->token);
    }

    /**
     * The token's length and its last four characters, which is enough to tell two
     * credentials apart in a log without being enough to use either.
     *
     * A token short enough that four characters would be a meaningful fraction of it is not a
     * real one, but the guard is here rather than assumed.
     */
    public function describe(): string
    {
        $length = strlen($this->token);

        return $length > 12
            ? sprintf('a BinaryLane API token ending %s (%d characters)', substr($this->token, -4), $length)
            : sprintf('a BinaryLane API token of %d characters', $length);
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->describe()];
    }
}
