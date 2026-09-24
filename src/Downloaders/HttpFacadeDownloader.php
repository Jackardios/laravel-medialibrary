<?php

namespace Spatie\MediaLibrary\Downloaders;

use Closure;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Throwable;

class HttpFacadeDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');

        try {
            Http::withUserAgent('Spatie MediaLibrary')
                ->throw(fn () => throw UnreachableUrl::create($url))
                ->setHandler($this->handler($url))
                ->sink($temporaryFile)
                ->get($url);
        } catch (ConnectionException|TooManyRedirectsException) {
            @unlink($temporaryFile);

            throw UnreachableUrl::create($url);
        } catch (Throwable $exception) {
            @unlink($temporaryFile);

            throw $exception;
        }

        return $temporaryFile;
    }

    /**
     * The handler that sends every request, redirects included. It checks the url, connects
     * to the checked address and stops the download at the maximum file size. Requests
     * faked with `Http::fake()` never reach it.
     */
    protected function handler(string $url): Closure
    {
        $send = Utils::chooseHandler();
        $guard = app(UrlGuard::class);

        return function (RequestInterface $request, array $options) use ($send, $guard, $url) {
            $uri = $request->getUri();
            $address = $guard->addressFor((string) $uri);

            // Without ext-curl Guzzle sends through PHP streams, which it cannot pin to an address.
            if (defined('CURLOPT_RESOLVE')) {
                unset($options['curl'][CURLOPT_RESOLVE]);
            }

            if ($address !== null) {
                // A proxy (Guzzle and curl read HTTP_PROXY and the like) would resolve the host again.
                unset($options['proxy']);

                if (defined('CURLOPT_PROXY')) {
                    $options['curl'][CURLOPT_PROXY] = '';
                }
            }

            if ($address !== null && defined('CURLOPT_RESOLVE') && filter_var(trim($uri->getHost(), '[]'), FILTER_VALIDATE_IP) === false) {
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $pinnedAddress = str_contains($address, ':') ? "[{$address}]" : $address;

                $options['curl'][CURLOPT_RESOLVE] = ["{$uri->getHost()}:{$port}:{$pinnedAddress}"];
            }

            if (isset($options['sink']) && config('media-library.max_file_size') !== null) {
                $options['sink'] = $this->limitSize($options['sink'], $url);
            }

            return $send($request, $options);
        };
    }

    protected function limitSize(mixed $sink, string $url): StreamInterface
    {
        $stream = is_string($sink) ? new LazyOpenStream($sink, 'w+') : Psr7Utils::streamFor($sink);

        return new class($stream, $url) implements StreamInterface
        {
            use StreamDecoratorTrait;

            private StreamInterface $stream;

            protected int $written = 0;

            public function __construct(StreamInterface $stream, protected string $url)
            {
                $this->stream = $stream;
            }

            public function write(string $string): int
            {
                $this->written += strlen($string);

                if ($this->written > config('media-library.max_file_size')) {
                    throw FileIsTooBig::create($this->url, $this->written);
                }

                return $this->stream->write($string);
            }
        };
    }
}
