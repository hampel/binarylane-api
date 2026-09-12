<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The cloud-init user-data a server was last initialised with.
 *
 * THE ONE ENDPOINT IN THE SPECIFICATION WITH NO ENVELOPE. `GET /v2/servers/{id}/user_data`
 * answers with this object bare - `{"user_data": "..."}` is the whole body, not
 * `{"user_data": {"user_data": "..."}}`. Every other read wraps its object in a named key.
 *
 * IT IS WHAT WAS USED, NOT WHAT IS RUNNING. User-data is consumed at first boot; a server
 * rebuilt with different user-data reports the new value, and one whose cloud-config was
 * edited from inside still reports the original. It also commonly contains secrets, which is
 * worth remembering before logging it.
 */
final class UserData implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $userData = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(Cast::string($row['user_data'] ?? null), $row);
    }

    /**
     * Whether the server was given any user-data at all.
     */
    public function isEmpty(): bool
    {
        return $this->userData === null || trim($this->userData) === '';
    }

    /**
     * Whether it looks like a cloud-config document, as opposed to a script.
     */
    public function isCloudConfig(): bool
    {
        return $this->userData !== null && str_starts_with(ltrim($this->userData), '#cloud-config');
    }

    /**
     * The content is withheld: user-data routinely carries credentials, and this is what a
     * var_dump or a stack trace prints.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['user_data' => $this->isEmpty()
            ? 'none'
            : sprintf('%d characters (withheld)', strlen((string) $this->userData))];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw !== [] ? $this->raw : ['user_data' => $this->userData];
    }
}
