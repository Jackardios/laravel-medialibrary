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

        $bytes = (string) inet_pton($address);

        // Multicast addresses (224.0.0.0/4) are in the global range, but no host has one.
        if (strlen($bytes) === 4) {
            return (ord($bytes[0]) & 0xF0) !== 0xE0;
        }

        // An IPv4 address carried in an IPv6 one reaches that IPv4 address: IPv4-compatible (::/96),
        // SIIT (::ffff:0:0:0/96) and NAT64 (64:ff9b::/96).
        foreach ([str_repeat("\0", 12), str_repeat("\0", 8)."\xff\xff\0\0", "\0\x64\xff\x9b".str_repeat("\0", 8)] as $prefix) {
            if (str_starts_with($bytes, $prefix)) {
                return $this->isPublic((string) inet_ntop(substr($bytes, 12)));
            }
        }

        // PHP counts these as global: multicast (ff00::/8), site-local (fec0::/10), local-use
        // NAT64 (64:ff9b:1::/48), documentation (3fff::/20) and SRv6 (5f00::/16).
        return ! ($bytes[0] === "\xff"
            || ($bytes[0] === "\xfe" && (ord($bytes[1]) & 0xC0) === 0xC0)
            || str_starts_with($bytes, "\0\x64\xff\x9b\0\x01")
            || ($bytes[0] === "\x3f" && (ord($bytes[1]) & 0xF0) === 0xF0)
            || str_starts_with($bytes, "\x5f\x00"));
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
