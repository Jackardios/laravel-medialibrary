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
use Spatie\MediaLibrary\MediaCollections\Exceptions\FunctionalityNotAvailable;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidUrl;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Throwable;

class HttpFacadeDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');

        try {
            $response = Http::withUserAgent('Spatie MediaLibrary')
                ->setHandler($this->handler($url))
                ->sink($temporaryFile)
                ->get($url);

            // A redirect without a location is not followed and not the file either.
            if (! $response->successful()) {
                throw UnreachableUrl::create($url);
            }
        } catch (Throwable $exception) {
            @unlink($temporaryFile);

            // Guzzle 8 wraps an exception thrown while the body is written or a redirect is checked.
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof FileIsTooBig || $cause instanceof InvalidUrl || $cause instanceof FunctionalityNotAvailable) {
                    throw $cause;
                }
            }

            if ($exception instanceof ConnectionException || $exception instanceof TooManyRedirectsException) {
                throw UnreachableUrl::create($url);
            }

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
            $pinned = $address !== null && filter_var(trim($uri->getHost(), '[]'), FILTER_VALIDATE_IP) === false;

            if ($pinned && ! $this->canConnectToCheckedAddress()) {
                throw FunctionalityNotAvailable::curlRequiredToConnectToCheckedAddress((string) $uri);
            }

            // A redirect is sent with the options of the request before it, pin included.
            if (defined('CURLOPT_RESOLVE')) {
                unset($options['curl'][CURLOPT_RESOLVE]);
            }

            // A proxy (Guzzle and curl read HTTP_PROXY and the like) would resolve the host again.
            // An empty proxy connects directly, also instead of the proxy of the environment.
            if ($address !== null) {
                $options['proxy'] = '';
            }

            if ($pinned) {
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

    /**
     * Guzzle connects to the checked address through curl; without it, Guzzle sends through
     * PHP streams, which would look the host up again.
     */
    protected function canConnectToCheckedAddress(): bool
    {
        return defined('CURLOPT_RESOLVE') && (function_exists('curl_exec') || function_exists('curl_multi_exec'));
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
