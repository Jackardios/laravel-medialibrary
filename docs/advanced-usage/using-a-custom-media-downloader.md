---
title: Using a custom media downloader
weight: 6
---

By default, when using the `addMediaFromUrl` method, the package internally uses `fopen` to download the media. In some cases though, the media can be behind a firewall or you need to attach specific headers to get access.

## Protection against private networks

To protect against server side request forgery (SSRF), both built-in downloaders refuse a URL whose host resolves to a private or reserved address: loopback (`localhost`, `127.0.0.1`, `::1`), private networks (`10.0.0.0/8`, `192.168.0.0/16`, ...), link-local addresses such as the cloud metadata endpoint `169.254.169.254`, and other reserved ranges. They check every redirect the same way, connect to the address they checked so a second DNS lookup cannot point elsewhere, and stop the download as soon as it exceeds `max_file_size`. A host with non-ascii characters has to be written in its punycode form (`xn--...`). A refused URL throws `Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidUrl`.

To download from an internal host, list it in the config. Wildcards are allowed:

```php
// config/media-library.php

'media_downloader_trusted_hosts' => ['files.internal.example', '*.cdn.internal.example'],
```

A proxy would look the host up again, so the `HttpFacadeDownloader` downloads a checked URL without one, ignoring `HTTP_PROXY` and the like. URLs of trusted hosts, and every URL when the check is disabled, go through the proxy as usual.

Setting `media_downloader_blocks_private_networks` to `false` (or `MEDIA_DOWNLOADER_BLOCKS_PRIVATE_NETWORKS=false`) disables the check. Only do that when every URL comes from a trusted source.

Without the curl extension the `HttpFacadeDownloader` still checks every URL, but it cannot make the connection to the checked address.

## Writing your own downloader

To do that, you can specify your own Media Downloader by creating a class that implements the `Downloader` interface. This method must fetch the resource and return the location of the temporary file.

A custom downloader is responsible for its own protection. `Spatie\MediaLibrary\Downloaders\UrlGuard` does the check: `addressFor($url)` throws for a URL that must not be downloaded, and returns the address to connect to (or `null` when the host is trusted and may be resolved as usual). Check every redirect too, or do not follow them.

For example, consider the following example which uses curl with custom headers to fetch the media.

```php
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\Downloaders\UrlGuard;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

class CustomDownloader implements Downloader {

    public function getTempFile($url){
        $address = app(UrlGuard::class)->addressFor($url);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');
        $fh = fopen($temporaryFile, 'w');

        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FAILONERROR     => true,
            CURLOPT_FILE            => $fh,
            CURLOPT_TIMEOUT         => 35,
            CURLOPT_FOLLOWLOCATION  => false,
        ];

        if ($address !== null) {
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
            $pinned = str_contains($address, ':') ? "[{$address}]" : $address; // IPv6
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$pinned}"];
            $options[CURLOPT_PROXY] = ''; // a proxy would resolve the host again
        }

        $headers = [
            'Content-Type: image/*',
            'User-Agent: Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:28.0) Gecko/20100101 Firefox/28.0',
        ];
        curl_setopt_array($curl, $options);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        if (false === curl_exec($curl)) {
            curl_close($curl);
            fclose($fh);
            throw UnreachableUrl::create($url);
        }
        curl_close($curl);
        fclose($fh);

        return $temporaryFile;
    }

}
```

## Using the Laravel Downloader

You may configure the medialibrary config to use a downloader compatible more
with Laravel that makes use of the built-in HTTP client. This is the quickest way
to mock any requests made to external URLs.

```php
    // config/media-library.php

    /*
     * When using the addMediaFromUrl method you may want to replace the default downloader.
     * This is particularly useful when the url of the image is behind a firewall and
     * need to add additional flags, possibly using curl.
     */
    'media_downloader' => Spatie\MediaLibrary\Downloaders\HttpFacadeDownloader::class,
```

This then makes it easier in tests to mock the download of files. Requests faked with `Http::fake()` are not checked against private networks.

```php
$url = 'http://medialibrary.spatie.be/assets/images/mountain.jpg';
$yourModel
   ->addMediaFromUrl($url)
   ->toMediaCollection();
```

with a test like this:

```php
Http::fake([
    // Stub a response where the body will be the contents of the file
    'http://medialibrary.spatie.be/assets/images/mountain.jpg' => Http::response('::file::'),
]);

// Execute code for the test

// Then check that a request for the file was made
Http::assertSent(function (Request $request) {
    return $request->url() == 'http://medialibrary.spatie.be/assets/images/mountain.jpg';
});

// We may also assert that the contents of any files created
// will contain `::file::`
```
