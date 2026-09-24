<?php

namespace Spatie\MediaLibrary\Downloaders;

use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidUrl;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

/**
 * Keeps media downloads from urls away from private networks (SSRF): the host of every url,
 * including the ones redirected to, has to resolve to public addresses only. Downloaders
 * connect to the checked address, so a second DNS lookup cannot send them elsewhere.
 */
class UrlGuard
{
    /**
     * The address to connect to for the url, or null when its host is not checked (a trusted
     * host, or the protection is disabled) and may be resolved as usual.
     *
     * @throws InvalidUrl when the url is not an http(s) url or its host resolves to a private or reserved address
     * @throws UnreachableUrl when its host does not resolve
     */
    public function addressFor(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw InvalidUrl::doesNotStartWithProtocol($url);
        }

        if (! config('media-library.media_downloader_blocks_private_networks', true) || $this->isTrusted($host)) {
            return null;
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw UnreachableUrl::create($url);
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw InvalidUrl::resolvesToPrivateAddress($url, $address);
            }
        }

        return $addresses[0];
    }

    public function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        // Multicast addresses (224.0.0.0/4, ff00::/8) are in the global range, but no host has one.
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? (ip2long($address) & 0xF0000000) !== 0xE0000000
            : ! str_starts_with($address, 'ff');
    }

    protected function isTrusted(string $host): bool
    {
        $trustedHosts = array_map('strtolower', config('media-library.media_downloader_trusted_hosts') ?? []);

        return $trustedHosts !== [] && Str::is($trustedHosts, $host);
    }

    /** @return array<int, string> */
    protected function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Also resolves numeric forms such as `2130706433` (127.0.0.1) and uses the hosts file.
        $addresses = gethostbynamel($host) ?: [];

        if ($addresses === []) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
