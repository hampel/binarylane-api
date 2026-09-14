<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Entity;

use Hampel\BinaryLane\Api\Enum\DomainRecordType;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Support\Cast;

/**
 * One record in a DNS zone.
 *
 * WHICH FIELDS MEAN ANYTHING DEPENDS ENTIRELY ON THE TYPE, and the specification says so
 * field by field: `priority` is for MX and SRV, `port` and `weight` for SRV alone, `flags`
 * and `tag` for CAA alone. A record read back from the API carries all five as nulls
 * whatever its type - so echoing a fetched record straight into a create would send fields
 * the type does not take. That is why there is a named constructor per type rather than one
 * constructor with nine optional arguments, and why toArray() emits only the fields that type
 * is allowed to carry.
 *
 *     DomainRecord::a('www', '203.0.113.10');
 *     DomainRecord::a(DomainRecord::APEX, '203.0.113.10');
 *     DomainRecord::mx('mail.example.com', priority: 10);
 *     DomainRecord::txt('_dmarc', 'v=DMARC1; p=quarantine');
 *     DomainRecord::srv('_sip._tcp', 'sip.example.com', port: 5060, priority: 10, weight: 5);
 *     DomainRecord::caa('issue', 'letsencrypt.org');
 *
 * THE APEX IS `@`, NOT AN EMPTY STRING. BinaryLane writes zone files the way BIND does: `@`
 * is the domain itself and `*` is a wildcard. Other DNS APIs use an empty name for the apex,
 * so this is the detail that breaks a port from one of them - APEX and WILDCARD are here so
 * it does not have to be remembered.
 *
 * THE TTL IS FIXED AT 3600 AND CANNOT BE CHOSEN. The specification is explicit: "The default
 * and only supported value is 3600. Leave null to accept this default." So a record does not
 * carry a TTL decision, and asking for a different one is rejected - which matters for
 * anything that changes DNS shortly before it matters, because an hour is the shortest notice
 * this provider gives.
 */
final class DomainRecord implements \JsonSerializable
{
    /**
     * The zone apex - the domain itself.
     */
    public const APEX = '@';

    /**
     * A wildcard record.
     */
    public const WILDCARD = '*';

    /**
     * The only TTL BinaryLane supports, in seconds.
     */
    public const TTL = 3600;

    /**
     * `priority`, `port` and `weight` are all unsigned 16-bit.
     */
    public const MAX_16_BIT = 65535;

    /**
     * @param  DomainRecordType|null  $type  null for a type this package does not know - one
     *                                       BinaryLane added after this release. Until 0.4.0
     *                                       such a record read as A, and replacing it sent it
     *                                       back as an A record. typeName() still says what it
     *                                       is, and `raw` still holds it
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?DomainRecordType $type,
        public readonly string $name = '',
        public readonly ?string $data = null,
        public readonly ?int $id = null,
        public readonly ?int $ttl = null,
        public readonly ?int $priority = null,
        public readonly ?int $port = null,
        public readonly ?int $weight = null,
        public readonly ?int $flags = null,
        public readonly ?string $tag = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * An IPv4 address record.
     */
    public static function a(string $name, string $address): self
    {
        return new self(DomainRecordType::A, self::name($name), self::required($address, 'An A record needs an IPv4 address.'));
    }

    /**
     * An IPv6 address record.
     */
    public static function aaaa(string $name, string $address): self
    {
        return new self(DomainRecordType::AAAA, self::name($name), self::required($address, 'An AAAA record needs an IPv6 address.'));
    }

    /**
     * An alias.
     *
     * A CNAME cannot coexist with any other record of the same name - that is DNS, not
     * BinaryLane - and a CNAME at the apex is invalid for the same reason.
     */
    public static function cname(string $name, string $target): self
    {
        return new self(DomainRecordType::CNAME, self::name($name), self::required($target, 'A CNAME needs a target.'));
    }

    /**
     * A mail exchanger.
     *
     * The target comes first because it is the part you always supply; `name` is the apex for
     * the ordinary case of mail addressed at the domain itself. Lower priority wins.
     */
    public static function mx(string $target, int $priority = 10, string $name = self::APEX): self
    {
        return new self(
            DomainRecordType::MX,
            self::name($name),
            self::required($target, 'An MX record needs a mail server.'),
            priority: self::unsigned16($priority, 'priority'),
        );
    }

    /**
     * A name server for the zone or a subdomain of it.
     */
    public static function ns(string $target, string $name = self::APEX): self
    {
        return new self(DomainRecordType::NS, self::name($name), self::required($target, 'An NS record needs a name server.'));
    }

    /**
     * A text record - SPF, DKIM, DMARC, a domain-verification token.
     */
    public static function txt(string $name, string $value): self
    {
        return new self(DomainRecordType::TXT, self::name($name), self::required($value, 'A TXT record needs a value.'));
    }

    /**
     * A service record.
     *
     * THE NAME IS THE DECORATED FORM HERE - `_sip._tcp`, underscores and all. Some providers
     * compose it from separate service and protocol fields; BinaryLane takes the assembled
     * name, so pass what you want in the zone file.
     *
     * Lower priority wins; among equal priorities, higher weight is preferred.
     */
    public static function srv(
        string $name,
        string $target,
        int $port,
        int $priority = 0,
        int $weight = 0,
    ): self {
        return new self(
            DomainRecordType::SRV,
            self::name($name),
            self::required($target, 'An SRV record needs a target.'),
            port: self::unsigned16($port, 'port'),
            priority: self::unsigned16($priority, 'priority'),
            weight: self::unsigned16($weight, 'weight'),
        );
    }

    /**
     * A certificate authority authorisation.
     *
     * `$tag` IS CONSTRAINED: lower-case letters and digits only, at most 15 characters - the
     * specification carries the pattern. In practice it is `issue`, `issuewild` or `iodef`.
     *
     * `$flags` is 0 to 255; 128 sets the critical bit, which tells a CA that does not
     * understand the tag to refuse rather than ignore.
     */
    public static function caa(string $tag, string $value, string $name = self::APEX, int $flags = 0): self
    {
        return new self(
            DomainRecordType::CAA,
            self::name($name),
            self::required($value, 'A CAA record needs a value - an authority, or a URL for iodef.'),
            flags: self::flags($flags),
            tag: self::tag($tag),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            DomainRecordType::tryFrom(Cast::string($row['type'] ?? null) ?? ''),
            Cast::string($row['name'] ?? null) ?? '',
            Cast::string($row['data'] ?? null),
            Cast::int($row['id'] ?? null),
            Cast::int($row['ttl'] ?? null),
            Cast::int($row['priority'] ?? null),
            Cast::int($row['port'] ?? null),
            Cast::int($row['weight'] ?? null),
            Cast::int($row['flags'] ?? null),
            Cast::string($row['tag'] ?? null),
            $row,
        );
    }

    /**
     * The create payload: `type`, `name`, `data`, and only the extra fields that type carries.
     *
     * The filtering is the point - see the class note. `ttl` is not sent at all, because 3600
     * is the only value the API supports and sending it adds nothing.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->type === null) {
            throw new InvalidArgumentException(sprintf(
                'This record\'s type, "%s", is not one this package knows, so it cannot be sent '
                    . 'back without changing it. Update it through DomainRecords::update() with '
                    . 'an array, which sends only the fields given.',
                $this->typeName()
            ));
        }

        // The name goes through name() here rather than only in the factories, so a record
        // built with the constructor, or read from another provider's export with fromArray(),
        // still sends `@` for the apex rather than the empty string the API rejects.
        $payload = [
            'type' => $this->type->value,
            'name' => self::name($this->name),
            'data' => $this->data,
        ];

        if ($this->type->usesPriority() && $this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        if ($this->type->usesServiceFields()) {
            foreach (['port' => $this->port, 'weight' => $this->weight] as $key => $value) {
                if ($value !== null) {
                    $payload[$key] = $value;
                }
            }
        }

        if ($this->type->usesCertificateAuthorityFields()) {
            foreach (['flags' => $this->flags, 'tag' => $this->tag] as $key => $value) {
                if ($value !== null) {
                    $payload[$key] = $value;
                }
            }
        }

        return $payload;
    }

    /**
     * The update payload.
     *
     * THE UPDATE IS A PUT AND IT BEHAVES LIKE A PATCH, with a distinction the verb hides.
     * The specification: "Any values not provided will be retained. Provide empty strings to
     * clear existing string values, nulls to retain the existing values."
     *
     * So on an update, null and empty string are opposites - omitting a field keeps it, and
     * sending `""` erases it. This method sends every field this record's type carries, which
     * is what you want when the record in hand is the record you mean to end up with.
     * Endpoint\DomainRecords::update() takes an array for the narrower case of changing one
     * field and leaving the rest untouched.
     *
     * @return array<string, mixed>
     */
    public function toUpdateArray(): array
    {
        return $this->toArray();
    }

    public function withName(string $name): self
    {
        return $this->with(name: self::name($name));
    }

    public function withData(string $data): self
    {
        return $this->with(data: $data);
    }

    /**
     * Lower wins. Meaningful on MX and SRV; not sent elsewhere.
     */
    public function withPriority(int $priority): self
    {
        return $this->with(priority: self::unsigned16($priority, 'priority'));
    }

    /**
     * Whether this record is at the zone apex.
     */
    public function isApex(): bool
    {
        return $this->name === self::APEX || $this->name === '';
    }

    public function isWildcard(): bool
    {
        return str_starts_with($this->name, self::WILDCARD);
    }

    /**
     * Whether this is a record the zone owner may create and change.
     *
     * False for the SOA, which every zone has and BinaryLane maintains - and for a type this
     * package does not know, since nothing about it can be sent safely.
     */
    public function isManageable(): bool
    {
        return $this->type?->isManageable() ?? false;
    }

    /**
     * The record type as a string, including one this package does not know - for a report,
     * where "unknown" says less than the name BinaryLane gave it.
     */
    public function typeName(): string
    {
        return $this->type->value ?? Cast::string($this->raw['type'] ?? null) ?? '';
    }

    /**
     * The record's fully qualified name.
     *
     * The API returns `name` relative to the zone and never says which zone a record is in, so
     * the zone has to be supplied. `@` and an empty name both answer as the zone itself.
     */
    public function fqdn(string $zone): string
    {
        $zone = trim($zone, '. ');
        $name = trim($this->name, '. ');

        return $name === '' || $name === self::APEX ? $zone : $name . '.' . $zone;
    }

    /**
     * The TTL that actually applies, which is the only one BinaryLane supports.
     */
    public function effectiveTtl(): int
    {
        return $this->ttl ?? self::TTL;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        // A record of unknown type has nothing toArray() will send, and json_encode() is no
        // place to raise it.
        return $this->raw !== [] || $this->type === null ? $this->raw : $this->toArray();
    }

    private function with(
        ?string $name = null,
        ?string $data = null,
        ?int $priority = null,
    ): self {
        return new self(
            $this->type,
            $name ?? $this->name,
            $data ?? $this->data,
            $this->id,
            $this->ttl,
            $priority ?? $this->priority,
            $this->port,
            $this->weight,
            $this->flags,
            $this->tag,
            $this->raw,
        );
    }

    /**
     * A name is trimmed, and an empty one becomes the apex marker rather than staying empty.
     *
     * The specification requires at least one character, and an empty name is what someone
     * porting from another DNS API writes for the apex. Turning it into `@` here is the
     * difference between a record at the apex and a 400 about the field.
     */
    private static function name(string $name): string
    {
        $name = trim($name);

        return $name === '' ? self::APEX : $name;
    }

    private static function required(string $value, string $message): string
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        return trim($value);
    }

    private static function unsigned16(int $value, string $field): int
    {
        if ($value < 0 || $value > self::MAX_16_BIT) {
            throw new InvalidArgumentException(sprintf(
                'A DNS record %s is 0 to %d; %d was given.',
                $field,
                self::MAX_16_BIT,
                $value
            ));
        }

        return $value;
    }

    private static function flags(int $flags): int
    {
        if ($flags < 0 || $flags > 255) {
            throw new InvalidArgumentException(sprintf(
                'CAA flags are an unsigned byte, 0 to 255; %d was given. 128 sets the critical bit.',
                $flags
            ));
        }

        return $flags;
    }

    private static function tag(string $tag): string
    {
        $tag = trim($tag);

        if ($tag === '' || strlen($tag) > 15 || preg_match('/^[a-z0-9]+$/', $tag) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'A CAA tag is 1 to 15 lower-case letters and digits - "issue", "issuewild" or '
                    . '"iodef" in practice; "%s" is not one.',
                $tag
            ));
        }

        return $tag;
    }
}
