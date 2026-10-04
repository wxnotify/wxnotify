<?php

namespace App\Support\Delivery;

/**
 * Pins an outbound connection to the addresses a guard vetted.
 *
 * A guard that looks a host name up and then lets curl look it up again can be
 * rebound: a hostile DNS server answers the guard with a public address and curl
 * with a private one. The guard therefore resolves the name once, judges that
 * answer, and hands the HTTP client exactly those addresses as CURLOPT_RESOLVE.
 * TLS still validates against the host name, since the URL itself is untouched.
 */
final class PinnedHost
{
    /**
     * Guzzle options that make curl connect to `$host` on each of `$ports` only at `$addresses`.
     *
     * A trailing-dot host is pinned under both spellings because curl matches
     * the entry against the host exactly as written in the URL.
     *
     * @param  list<int>  $ports
     * @param  list<string>  $addresses  already normalised and vetted
     * @return array{curl: array<int, list<string>>}
     */
    public static function options(string $host, array $ports, array $addresses): array
    {
        $host = strtolower($host);
        $targets = implode(',', array_map(
            static fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        ));

        $entries = [];

        foreach ($ports as $port) {
            foreach ([$host, rtrim($host, '.')] as $spelling) {
                $entries[] = "{$spelling}:{$port}:{$targets}";
            }
        }

        return ['curl' => [CURLOPT_RESOLVE => array_values(array_unique($entries))]];
    }

    /**
     * Canonical text form of each valid address, duplicates and junk dropped.
     *
     * @param  array<int, string>  $addresses
     * @return list<string>
     */
    public static function normalize(array $addresses): array
    {
        $normalized = [];

        foreach ($addresses as $address) {
            $packed = @inet_pton((string) $address);

            if ($packed !== false) {
                $normalized[] = (string) inet_ntop($packed);
            }
        }

        return array_values(array_unique($normalized));
    }
}
