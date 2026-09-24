<?php

namespace Spatie\MediaLibrary\Downloaders;

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Throwable;

class DefaultDownloader implements Downloader
{
    protected int $maxRedirects = 5;

    public function getTempFile(string $url): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');

        try {
            $this->download($url, $temporaryFile);
        } catch (Throwable $exception) {
            @unlink($temporaryFile);

            throw $exception;
        }

        return $temporaryFile;
    }

    protected function download(string $url, string $temporaryFile): void
    {
        $guard = app(UrlGuard::class);
        $location = $url;

        // Redirects are followed here rather than by PHP, so every url is checked before it is requested.
        for ($redirects = 0; $redirects <= $this->maxRedirects; $redirects++) {
            $address = $guard->addressFor($location);

            if (! $stream = @fopen($this->urlToRequest($location, $address), 'r', false, $this->context($location, $address))) {
                throw UnreachableUrl::create($url);
            }

            try {
                $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
                $status = $this->status($headers);

                if ($status >= 300 && $status < 400 && ($next = $this->header($headers, 'Location')) !== null) {
                    $location = $this->resolveLocation($location, $next);

                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw UnreachableUrl::create($url);
                }

                $this->copyToFile($stream, $temporaryFile, $url);

                return;
            } finally {
                fclose($stream);
            }
        }

        throw UnreachableUrl::create($url);
    }

    /**
     * @param  resource  $stream
     */
    protected function copyToFile($stream, string $temporaryFile, string $url): void
    {
        $maxFileSize = config('media-library.max_file_size');
        $file = fopen($temporaryFile, 'wb');
        $size = 0;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    throw UnreachableUrl::create($url);
                }

                $size += strlen($chunk);

                if ($maxFileSize !== null && $size > $maxFileSize) {
                    throw FileIsTooBig::create($url, $size);
                }

                fwrite($file, $chunk);
            }
        } finally {
            fclose($file);
        }
    }

    /**
     * The url with its host replaced by the checked address, so the connection goes where the check said.
     */
    protected function urlToRequest(string $url, ?string $address): string
    {
        if ($address === null) {
            return $url;
        }

        $parts = parse_url($url);
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$parts['scheme']}://{$host}{$port}".($parts['path'] ?? '/').$query;
    }

    /**
     * @return resource
     */
    protected function context(string $url, ?string $address)
    {
        $headers = ['User-Agent: Spatie MediaLibrary'];

        if ($address !== null) {
            $port = parse_url($url, PHP_URL_PORT);
            $headers[] = 'Host: '.parse_url($url, PHP_URL_HOST).($port ? ":{$port}" : '');

            // The url requested has no credentials, see urlToRequest().
            if (($user = parse_url($url, PHP_URL_USER)) !== null) {
                $headers[] = 'Authorization: Basic '.base64_encode(rawurldecode($user).':'.rawurldecode((string) parse_url($url, PHP_URL_PASS)));
            }
        }

        return stream_context_create([
            'ssl' => [
                'verify_peer' => config('media-library.media_downloader_ssl'),
                'verify_peer_name' => config('media-library.media_downloader_ssl'),
                // The certificate is checked against the host, not the address connected to.
                'peer_name' => trim((string) parse_url($url, PHP_URL_HOST), '[]'),
            ],
            'http' => [
                'header' => $headers,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function status(array $headers): int
    {
        preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0] ?? '', $matches);

        return (int) ($matches[1] ?? 0);
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, "{$name}:") === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    protected function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'];

        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }

        $origin = "{$scheme}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        if (str_starts_with($location, '?')) {
            return $origin.$path.$location;
        }

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$location;
    }
}
