<?php

use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

beforeEach(function () {
    $this->config = app('config');

    $this->media = $this->testModelWithConversion->addMedia($this->getTestPng())->toMediaCollection();

    $this->conversion = ConversionCollection::createForMedia($this->media)->getByName('thumb');

    $this->conversionKeepingOriginalImageFormat = ConversionCollection::createForMedia($this->media)->getByName('keep_original_format');

    $this->urlGenerator = new DefaultUrlGenerator($this->config);
    $this->pathGenerator = new DefaultPathGenerator();

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
