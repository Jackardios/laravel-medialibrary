<?php

use Spatie\MediaLibrary\Downloaders\UrlGuard;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidUrl;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

it('refuses a url whose host resolves to a private or reserved address', function (string $url) {
    expect(fn () => (new UrlGuard)->addressFor($url))
        ->toThrow(InvalidUrl::class, 'private or reserved address');
})->with([
    'loopback' => 'http://127.0.0.1/image.jpg',
    'localhost' => 'http://localhost/image.jpg',
    'loopback in decimal' => 'http://2130706433/image.jpg',
    'ipv6 loopback' => 'http://[::1]/image.jpg',
    'ipv4-mapped ipv6' => 'http://[::ffff:127.0.0.1]/image.jpg',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data',
    'private network' => 'https://10.0.0.1/image.jpg',
    'carrier-grade nat' => 'http://100.64.0.1/image.jpg',
    'unique local ipv6' => 'http://[fd00::1]/image.jpg',
    'unspecified' => 'http://0.0.0.0/image.jpg',
    'multicast' => 'http://224.0.0.1/image.jpg',
]);

it('returns the address to connect to for a public host', function (string $url, string $address) {
    expect((new UrlGuard)->addressFor($url))->toBe($address);
})->with([
    'ipv4' => ['https://93.184.215.14/image.jpg', '93.184.215.14'],
    'ipv6' => ['https://[2606:4700::1111]/image.jpg', '2606:4700::1111'],
]);

it('does not check a trusted host', function (string $trustedHost) {
    config()->set('media-library.media_downloader_trusted_hosts', [$trustedHost]);

    expect((new UrlGuard)->addressFor('http://files.internal.example/image.jpg'))->toBeNull();
})->with(['files.internal.example', '*.internal.example', 'FILES.internal.example']);

it('checks a host the trusted hosts do not match', function () {
    config()->set('media-library.media_downloader_trusted_hosts', ['*.internal.example']);

    expect(fn () => (new UrlGuard)->addressFor('http://127.0.0.1/image.jpg'))->toThrow(InvalidUrl::class);
});

it('does not check any host when the protection is disabled', function () {
    config()->set('media-library.media_downloader_blocks_private_networks', false);

    expect((new UrlGuard)->addressFor('http://127.0.0.1/image.jpg'))->toBeNull();
});

it('refuses a url that is not an http url', function (string $url) {
    expect(fn () => (new UrlGuard)->addressFor($url))->toThrow(InvalidUrl::class);
})->with(['file:///etc/passwd', 'ftp://example.com/image.jpg', 'http:///image.jpg']);

it('throws when the host does not resolve', function () {
    expect(fn () => (new UrlGuard)->addressFor('http://does-not-exist.invalid/image.jpg'))
        ->toThrow(UnreachableUrl::class);
});
