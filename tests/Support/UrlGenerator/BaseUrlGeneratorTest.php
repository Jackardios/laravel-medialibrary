<?php

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

beforeEach(function () {
    $this->config = app('config');

    $this->media = $this->testModelWithConversion->addMedia($this->getTestPng())->toMediaCollection();

    $this->conversion = ConversionCollection::createForMedia($this->media)->getByName('thumb');

    $this->conversionKeepingOriginalImageFormat = ConversionCollection::createForMedia($this->media)->getByName('keep_original_format');

    $this->urlGenerator = new DefaultUrlGenerator($this->config);
    $this->pathGenerator = new DefaultPathGenerator;

    $this->urlGenerator
        ->setMedia($this->media)
        ->setConversion($this->conversion)
        ->setPathGenerator($this->pathGenerator);
});

it('can get the path relative to the root of media folder', function () {
    $pathRelativeToRoot = $this->media->id.'/conversions/test-'.$this->conversion->getName().'.jpg';

    expect($this->urlGenerator->getPathRelativeToRoot())->toEqual($pathRelativeToRoot);
});

it('can get the path relative to the root of media folder when keeping the original image format', function () {
    $this->urlGenerator->setConversion($this->conversionKeepingOriginalImageFormat);

    $pathRelativeToRoot = $this->media->id
        .'/conversions/'.
        'test-'.$this->conversionKeepingOriginalImageFormat->getName()
        .'.png';

    expect($this->urlGenerator->getPathRelativeToRoot())->toEqual($pathRelativeToRoot);
});

it('appends a version string when versioning is enabled', function () {
    config()->set('media-library.version_urls', true);

    $url = '/media/'.$this->media->id.'/conversions/test-'.$this->conversion->getName().'.jpg?v='.$this->media->updated_at->timestamp;

    expect($this->urlGenerator->getUrl())->toEqual($url);

    config()->set('media-library.version_urls', false);

    $url = '/media/'.$this->media->id.'/conversions/test-'.$this->conversion->getName().'.jpg';

    expect($this->urlGenerator->getUrl())->toEqual($url);
});

it('can get the responsive images directory url', function () {
    $this->config->set('filesystems.disks.public.url', 'http://localhost/media/');

    expect($this->urlGenerator->getResponsiveImagesDirectoryUrl())->toEqual('/media/1/responsive-images/');
});

it('correctly encodes percent signs in filenames when getting url', function () {
    $media = $this->testModel->addMedia($this->getTestFilesDirectory('test_.jpg'))
        ->usingFileName('IMG_5405%20copy.jpg')
        ->toMediaCollection();

    expect($media->getUrl())->toContain('IMG_5405%2520copy.jpg');
    expect($media->getPath())->toContain('IMG_5405%20copy.jpg');
});

it('encodes file names on an s3 disk with a configured base url', function () {
    // Laravel appends the path to a configured `url` as is (S3 behind a CDN, R2, ...), while
    // without one the S3 client builds and encodes the object url itself.
    config()->set('filesystems.disks.cdn', [
        'driver' => 's3',
        'url' => 'https://cdn.example.com',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
    ]);

    $media = $this->testModel->addMedia($this->getTestFilesDirectory('test_.jpg'))
        ->usingFileName('IMG_5405%20café.jpg')
        ->toMediaCollection();

    $media->disk = 'cdn';

    expect($media->getUrl())->toBe("https://cdn.example.com/{$media->id}/IMG_5405%2520caf%C3%A9.jpg");

    config()->set('filesystems.disks.cdn.url', null);
    Storage::forgetDisk('cdn');

    expect($media->getUrl())->toBe("https://bucket.s3.amazonaws.com/{$media->id}/IMG_5405%2520caf%C3%A9.jpg");
});

it('falls back to the originals disk for conversion urls when conversions_disk is null', function () {
    // Point the application default disk elsewhere so a wrong null-fallback
    // (Storage::disk(null) → default disk) would be observable in the URL.
    $this->config->set('filesystems.default', 'secondMediaDisk');

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    // Legacy / non-FileAdder rows can carry a null conversions_disk.
    $media->conversions_disk = null;

    // With the fix the conversion URL resolves against the originals disk ('public', /media),
    // not the application default disk ('secondMediaDisk', /media2).
    expect($media->getUrl('thumb'))->toEqual("/media/{$media->id}/conversions/test-thumb.jpg");
});

it('leaves out the version of media without an update time', function () {
    config()->set('media-library.version_urls', true);

    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();
    $media->updated_at = null;

    expect($media->getUrl())->toBe("/media/{$media->id}/test.jpg")
        ->and($media->getResponsiveImageUrls())->each->not->toContain('?v=');
});
