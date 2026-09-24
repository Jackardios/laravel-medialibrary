<?php

use Carbon\Carbon;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\WidthCalculator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\WidthCalculators\FixedWidthCalculator;

beforeEach(function () {
    $this->fileName = 'test';
    $this->fileNameWithUnderscore = 'test_';
});

test('a media instance can get responsive image urls', function () {
    $this
        ->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->toMediaCollection();

    $media = $this->testModelWithResponsiveImages->getFirstMedia();

    $this->assertEquals([
        "/media/1/responsive-images/{$this->fileName}___media_library_original_340_280.jpg",
        "/media/1/responsive-images/{$this->fileName}___media_library_original_284_234.jpg",
        "/media/1/responsive-images/{$this->fileName}___media_library_original_237_195.jpg",
    ], $media->getResponsiveImageUrls());

    $this->assertEquals([
        "/media/1/responsive-images/{$this->fileName}___thumb_50_41.jpg",
    ], $media->getResponsiveImageUrls('thumb'));

    expect($media->getResponsiveImageUrls('non-existing-conversion'))->toEqual([]);
});

test('a media instance can generate the contents of scrset', function () {
    $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->toMediaCollection();

    $media = $this->testModelWithResponsiveImages->getFirstMedia();

    $this->assertStringContainsString(
        "/media/1/responsive-images/{$this->fileName}___media_library_original_340_280.jpg 340w, /media/1/responsive-images/{$this->fileName}___media_library_original_284_234.jpg 284w, /media/1/responsive-images/{$this->fileName}___media_library_original_237_195.jpg 237w",
        $media->getSrcset()
    );
    expect($media->getSrcset())->toContain('data:image/svg+xml;base64');

    $this->assertStringContainsString(
        "/media/1/responsive-images/{$this->fileName}___thumb_50_41.jpg 50w",
        $media->getSrcset('thumb')
    );
    expect($media->getSrcset('thumb'))->toContain('data:image/svg+xml;base64,');
});

test('a media instance can generate the contents of scrset with versioned urls', function (mixed $versionUrls) {
    config()->set('media-library.version_urls', $versionUrls);

    $this->travelTo(Carbon::create(2023, 3, 24, 14));

    $this->freezeTime(function (Carbon $time) {
        $this->testModelWithResponsiveImages
            ->addMedia($this->getTestJpg())
            ->withResponsiveImages()
            ->toMediaCollection();

        $media = $this->testModelWithResponsiveImages->getFirstMedia();

        $timestamp = $time->timestamp;

        $this->assertStringContainsString(
            "/media/1/responsive-images/{$this->fileName}___media_library_original_340_280.jpg?v={$timestamp} 340w, /media/1/responsive-images/{$this->fileName}___media_library_original_284_234.jpg?v={$timestamp} 284w, /media/1/responsive-images/{$this->fileName}___media_library_original_237_195.jpg?v={$timestamp} 237w",
            $media->getSrcset()
        );
        expect($media->getSrcset())->toContain('data:image/svg+xml;base64');

        $this->assertStringContainsString(
            "/media/1/responsive-images/{$this->fileName}___thumb_50_41.jpg?v={$timestamp} 50w",
            $media->getSrcset('thumb')
        );
        expect($media->getSrcset('thumb'))->toContain('data:image/svg+xml;base64,');
    });
})->with([true, 1]);

test('a responsive image can return some properties', function () {
    $this->testModel
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->toMediaCollection();

    $media = $this->testModel->getFirstMedia();

    $responsiveImage = $media->responsiveImages()->files->first();

    expect($responsiveImage->generatedFor())->toEqual('media_library_original');

    expect($responsiveImage->width())->toEqual(340);

    expect($responsiveImage->height())->toEqual(280);
});

test('responsive image generation respects the conversion quality setting', function () {
    $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->toMediaCollection('default');

    $standardQualityResponsiveConversion = $this->getTempDirectory("media/1/responsive-images/{$this->fileName}___standardQuality_340_280.jpg");
    $lowerQualityResponsiveConversion = $this->getTempDirectory("media/1/responsive-images/{$this->fileName}___lowerQuality_340_280.jpg");

    expect(filesize($lowerQualityResponsiveConversion))->toBeLessThan(filesize($standardQualityResponsiveConversion));
});

test('a media instance can get responsive image urls with conversions stored on second media disk', function () {
    $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    $media = $this->testModelWithResponsiveImages->getFirstMedia();

    $this->assertEquals([
        "/media2/1/responsive-images/{$this->fileName}___thumb_50_41.jpg",
    ], $media->getResponsiveImageUrls('thumb'));
});

it('can handle file names with underscore', function () {
    $this
        ->testModelWithResponsiveImages
        ->addMedia($this->getTestImageEndingWithUnderscore())
        ->withResponsiveImages()
        ->toMediaCollection();

    $media = $this->testModelWithResponsiveImages->getFirstMedia();

    $this->assertSame([
        "/media/1/responsive-images/{$this->fileNameWithUnderscore}___media_library_original_340_280.jpg",
        "/media/1/responsive-images/{$this->fileNameWithUnderscore}___media_library_original_284_234.jpg",
        "/media/1/responsive-images/{$this->fileNameWithUnderscore}___media_library_original_237_195.jpg",
    ], $media->getResponsiveImageUrls());

    $this->assertSame([
        "/media/1/responsive-images/{$this->fileNameWithUnderscore}___thumb_50_41.jpg",
    ], $media->getResponsiveImageUrls('thumb'));

    expect($media->getResponsiveImageUrls('non-existing-conversion'))->toBe([]);
});

test('deleting a responsive image keeps the other images of its conversion', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $kept = $media->responsive_images['media_library_original']['urls'];
    $deleted = array_shift($kept);

    $media->responsiveImages()->files->first()->delete();

    $directory = $this->getMediaDirectory("{$media->id}/responsive-images");

    expect($media->fresh()->responsive_images['media_library_original']['urls'])->toBe($kept)
        ->and($media->fresh()->responsive_images['media_library_original'])->toHaveKey('base64svg')
        ->and("{$directory}/{$deleted}")->not->toBeFile();

    foreach ($kept as $fileName) {
        expect("{$directory}/{$fileName}")->toBeFile();
    }
});

test('deleting the last responsive image of a conversion removes its entry', function () {
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection()->fresh();

    $media->responsiveImages('thumb')->files->first()->delete();

    expect($media->fresh()->responsive_images)->not->toHaveKey('thumb');
});

test('deleting responsive images without files removes their entry', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection();

    // A width calculator that returns no widths leaves an entry without files.
    app()->bind(WidthCalculator::class, fn () => new FixedWidthCalculator([]));
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media->fresh());

    expect($media->fresh()->responsive_images['media_library_original']['urls'])->toBe([]);

    $media->fresh()->responsiveImages()->delete();

    expect($media->fresh()->responsive_images)->not->toHaveKey('media_library_original');
});

test('the urls of a set of responsive images share one url generator', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->toMediaCollection()
        ->fresh();

    $urlGenerators = 0;
    app()->beforeResolving(DefaultUrlGenerator::class, function () use (&$urlGenerators) {
        $urlGenerators++;
    });

    expect($media->hasResponsiveImages())->toBeTrue()
        ->and($urlGenerators)->toBe(0);

    expect($media->getResponsiveImageUrls())->toHaveCount(3)
        ->and($urlGenerators)->toBe(1);

    $media->getSrcset();

    expect($urlGenerators)->toBe(2);
});
