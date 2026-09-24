<?php

use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\Downloaders\HttpFacadeDownloader;
use Spatie\MediaLibrary\Downloaders\UrlGuard;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidUrl;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Spatie\MediaLibrary\Tests\TestSupport\LocalHttpServer;

/**
 * Resolves `media.test` to the local server and treats it as public, so a test can
 * tell whether a downloader connects to the address the guard checked.
 */
class UrlGuardResolvingMediaTest extends UrlGuard
{
    public function isPublic(string $address): bool
    {
        return true;
    }

    protected function resolve(string $host): array
    {
        return $host === 'media.test' ? ['127.0.0.1'] : parent::resolve($host);
    }
}

function download(string $downloader, string $url): string
{
    return (new $downloader)->getTempFile($url);
}

dataset('downloaders', [
    'default' => DefaultDownloader::class,
    'http facade' => HttpFacadeDownloader::class,
]);

beforeEach(function () {
    config()->set('media-library.media_downloader_trusted_hosts', ['127.0.0.1']);
});

it('downloads a url to a temporary file', function (string $downloader) {
    $temporaryFile = download($downloader, LocalHttpServer::url('/files/test.jpg'));

    expect(File::get($temporaryFile))->toBe(File::get($this->getTestJpg()));
})->with('downloaders');

it('refuses a url on a private network by default', function (string $downloader) {
    config()->set('media-library.media_downloader_trusted_hosts', []);

    expect(fn () => download($downloader, LocalHttpServer::url('/files/test.jpg')))
        ->toThrow(InvalidUrl::class, 'private or reserved address');
})->with('downloaders');

it('refuses a redirect to a private network', function (string $downloader) {
    $target = 'http://localhost:'.LocalHttpServer::port().'/files/test.jpg';

    expect(fn () => download($downloader, LocalHttpServer::url('/redirect?to='.urlencode($target))))
        ->toThrow(InvalidUrl::class, 'private or reserved address');
})->with('downloaders');

it('follows a redirect', function (string $downloader, string $target) {
    $temporaryFile = download($downloader, LocalHttpServer::url('/redirect?status=301&to='.urlencode($target)));

    expect(File::get($temporaryFile))->toBe(File::get($this->getTestJpg()));
})->with('downloaders')->with([
    'absolute' => fn () => LocalHttpServer::url('/files/test.jpg'),
    'relative' => '/files/test.jpg',
]);

it('connects to the address the guard checked', function (string $downloader) {
    app()->instance(UrlGuard::class, new UrlGuardResolvingMediaTest);

    $temporaryFile = download($downloader, 'http://media.test:'.LocalHttpServer::port().'/host');

    expect(File::get($temporaryFile))->toBe('media.test:'.LocalHttpServer::port());
})->with('downloaders');

it('connects to the address the guard checked when a proxy is configured', function (string $downloader) {
    app()->instance(UrlGuard::class, new UrlGuardResolvingMediaTest);

    // Guzzle and curl read these; a proxy would resolve the host again. Nothing listens on port 1.
    foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy'] as $variable) {
        putenv("{$variable}=http://127.0.0.1:1");
        $_SERVER[$variable] = 'http://127.0.0.1:1';
    }

    try {
        $temporaryFile = download($downloader, 'http://media.test:'.LocalHttpServer::port().'/host');
    } finally {
        foreach (['HTTP_PROXY', 'http_proxy', 'HTTPS_PROXY', 'https_proxy'] as $variable) {
            putenv($variable);
            unset($_SERVER[$variable]);
        }
    }

    expect(File::get($temporaryFile))->toBe('media.test:'.LocalHttpServer::port());
})->with('downloaders');

it('stops downloading a file bigger than the maximum file size', function (string $downloader) {
    config()->set('media-library.max_file_size', 100 * 1024);
    $filesBefore = glob(sys_get_temp_dir().'/media-library*') ?: [];

    expect(fn () => download($downloader, LocalHttpServer::url('/large?bytes='.(1024 * 1024))))
        ->toThrow(FileIsTooBig::class);

    expect(array_values(array_diff(glob(sys_get_temp_dir().'/media-library*') ?: [], $filesBefore)))->toBe([]);
})->with('downloaders');

it('downloads a file of the maximum file size', function (string $downloader) {
    config()->set('media-library.max_file_size', 100 * 1024);

    $temporaryFile = download($downloader, LocalHttpServer::url('/large?bytes='.(100 * 1024)));

    expect(filesize($temporaryFile))->toBe(100 * 1024);

    unlink($temporaryFile);
})->with('downloaders');

it('throws when the url cannot be downloaded', function (string $downloader, string $path) {
    expect(fn () => download($downloader, LocalHttpServer::url($path)))
        ->toThrow(UnreachableUrl::class);
})->with('downloaders')->with([
    'not found' => '/files/missing.jpg',
    'redirect loop' => '/redirect-loop',
]);
