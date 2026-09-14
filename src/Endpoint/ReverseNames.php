<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Endpoint;

use Hampel\BinaryLane\Api\Support\Cast;

/**
 * The global IPv6 reverse nameservers.
 *
 * `/v2/reverse_names/ipv6`
 *
 * ACCOUNT-WIDE, NOT PER SERVER, AND THAT IS THE WHOLE POINT OF IT. Addresses in an allocated
 * IPv6 floating range have no PTR records under BinaryLane's default configuration; setting
 * nameservers here delegates PTR lookups for EVERY IPv6-enabled server on the account to
 * nameservers you run. The per-server equivalent is
 * ServerActions::changeIpv6ReverseNameservers().
 *
 * THE LIST IS REPLACED, NOT APPENDED TO. The specification says it plainly: "any existing
 * reverse name servers that are omitted from the list will be removed". So adding one means
 * sending the others, which is what `add()` does - at the cost of a read, and without being
 * atomic.
 */
final class ReverseNames extends Endpoint
{
    public const COLLECTION = 'reverse_nameservers';

    /**
     * Every configured nameserver.
     *
     * THE ONLY COLLECTION IN THE API THAT IS A LIST OF STRINGS rather than of objects, which
     * is why this endpoint has no `list()` returning a Page: Page maps each row through a
     * constructor, and there is nothing here to construct. It pages internally instead and
     * hands back the strings.
     *
     * @return list<string>
     */
    public function all(?int $perPage = null): array
    {
        $names = [];
        $response = $this->apiGet('reverse_names/ipv6', $perPage === null ? [] : ['per_page' => $perPage]);

        while (true) {
            foreach (Cast::strings($response->requireArray(self::COLLECTION)) as $name) {
                $names[] = $name;
            }

            $next = Cast::string(
                Cast::object(Cast::object($response->value('links'))['pages'] ?? null)['next'] ?? null
            );

            if ($next === null || trim($next) === '') {
                return $names;
            }

            // follow(), not get(): the link came out of a response body, and follow() is what
            // refuses one pointing anywhere but the configured API.
            $response = $this->connection->follow($next);
        }
    }

    /**
     * Replace the whole set of nameservers.
     *
     * AN EMPTY LIST REMOVES THEM ALL, which returns every IPv6-enabled server on the account to
     * having no PTR delegation.
     *
     * @param  list<string>  $nameservers
     * @return list<string>  what the account has afterwards
     */
    public function set(array $nameservers): array
    {
        $response = $this->apiPost('reverse_names/ipv6', [
            'reverse_nameservers' => array_values(array_map(trim(...), $nameservers)),
        ]);

        return Cast::strings($response->requireArray(self::COLLECTION));
    }

    /**
     * Add nameservers, keeping the ones already configured.
     *
     * TWO REQUESTS, AND NOT ATOMIC - the API only replaces, so this reads the current set
     * first. Duplicates are dropped.
     *
     * @param  list<string>  $nameservers
     * @return list<string>
     */
    public function add(array $nameservers): array
    {
        $existing = $this->all();

        foreach ($nameservers as $nameserver) {
            $nameserver = trim($nameserver);

            if ($nameserver !== '' && !in_array($nameserver, $existing, true)) {
                $existing[] = $nameserver;
            }
        }

        return $this->set($existing);
    }

    /**
     * Remove nameservers, keeping the rest. Two requests, not atomic - see add().
     *
     * @param  list<string>  $nameservers
     * @return list<string>
     */
    public function remove(array $nameservers): array
    {
        $remove = array_map(trim(...), $nameservers);

        return $this->set(array_values(array_filter(
            $this->all(),
            static fn (string $existing): bool => !in_array($existing, $remove, true)
        )));
    }

    /**
     * Remove every nameserver, returning the account to BinaryLane's default of no PTR
     * delegation.
     *
     * @return list<string>
     */
    public function clear(): array
    {
        return $this->set([]);
    }
}
