<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\Downloaders\HttpFacadeDownloader;

it('sends the request with its user agent', function () {
    Http::fake(['https://example.com/image.jpg' => Http::response('::file::')]);

    (new HttpFacadeDownloader)->getTempFile('https://example.com/image.jpg');

    Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'Spatie MediaLibrary'));
});

it('can be mocked easily for tests', function () {
    $url = 'https://example.com';

    Http::fake([
        // Stub a JSON response for GitHub endpoints...
        'https://example.com' => Http::response('::file::'),
    ]);

    $downloader = new HttpFacadeDownloader;

    $result = $downloader->getTempFile($url);

    expect($result)
        ->toBeString()
        ->and($result)
        ->toBeFile()
        ->and(File::get($result))
        ->toBe('::file::');

    Http::assertSent(function (Request $request) {
        return $request->url() == 'https://example.com';
    });
});

it('does not check a faked url', function () {
    Http::fake(['http://127.0.0.1/image.jpg' => Http::response('::file::')]);

    expect(File::get((new HttpFacadeDownloader)->getTempFile('http://127.0.0.1/image.jpg')))->toBe('::file::');
});
