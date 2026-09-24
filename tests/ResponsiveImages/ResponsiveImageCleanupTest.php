<?php

use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\TinyPlaceholderGenerator;
use Spatie\MediaLibrary\Support\FileRemover\FileRemoverFactory;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

class TestModelWithPrefixedResponsiveConversions extends TestModel
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(40)->withResponsiveImages()->nonQueued();
        $this->addMediaConversion('thumb_big')->width(80)->withResponsiveImages()->nonQueued();
        $this->addMediaConversion('thumb-large')->width(120)->withResponsiveImages()->nonQueued();
    }
}

function responsiveFilesOnDisk(Media $media): array
{
    $directory = test()->getMediaDirectory("{$media->id}/responsive-images");

    return collect(File::files($directory))->map->getFilename()->sort()->values()->all();
}

function registeredResponsiveFiles(Media $media): array
{
    return collect($media->fresh()->responsive_images)->pluck('urls')->flatten()->sort()->values()->all();
}

it('keeps the responsive images of conversions whose name starts with the regenerated one', function () {
    $media = TestModelWithPrefixedResponsiveConversions::first()->addMedia($this->getTestJpg())->toMediaCollection();

    app(FileManipulator::class)->regenerateDerivedFiles($media->fresh(), ['thumb']);

    expect(registeredResponsiveFiles($media))
        ->toHaveCount(3)
        ->toBe(responsiveFilesOnDisk($media));
});

it('removes only the responsive images of the given conversion', function () {
    $media = TestModelWithPrefixedResponsiveConversions::first()->addMedia($this->getTestJpg())->toMediaCollection();

    $media = $media->fresh();

    FileRemoverFactory::create($media)->removeResponsiveImages($media, 'thumb');

    expect(responsiveFilesOnDisk($media))->toBe(collect([
        ...$media->responsive_images['thumb_big']['urls'],
        ...$media->responsive_images['thumb-large']['urls'],
    ])->sort()->values()->all());
});

it('replaces the previous responsive images of a renamed media', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $media->file_name = 'renamed.jpg';
    $media->save();

    // A smaller source yields a different set of widths, so no old file is overwritten.
    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media->fresh(), $this->getSmallTestJpg());

    expect(responsiveFilesOnDisk($media))->toBe(registeredResponsiveFiles($media));
});

it('replaces the previous responsive images of a media with a custom name', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->usingName('My photo')->withResponsiveImages()->toMediaCollection();

    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media->fresh(), $this->getSmallTestJpg());

    expect(responsiveFilesOnDisk($media))->toBe(registeredResponsiveFiles($media));
});

function failTinyPlaceholders(): void
{
    config()->set('media-library.responsive_images.use_tiny_placeholders', true);

    app()->bind(TinyPlaceholderGenerator::class, fn () => new class implements TinyPlaceholderGenerator
    {
        public function generateTinyPlaceholder(string $sourceImagePath, string $tinyImageDestinationPath): void
        {
            throw new RuntimeException('placeholder failed');
        }
    });
}

it('keeps the previous responsive images when generating new ones fails', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $responsiveImages = $media->responsive_images;
    $files = responsiveFilesOnDisk($media);

    failTinyPlaceholders();

    // A smaller source yields a different set of widths, so no old file is overwritten.
    expect(fn () => app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, $this->getSmallTestJpg()))
        ->toThrow(RuntimeException::class, 'placeholder failed');

    expect($media->fresh()->responsive_images)->toBe($responsiveImages)
        ->and(responsiveFilesOnDisk($media))->toBe($files);
});

it('keeps the previous responsive images of a conversion when generating new ones fails', function () {
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection()->fresh();

    // Give the previous image a width the new set does not have, so it is not overwritten.
    $directory = $this->getMediaDirectory("{$media->id}/responsive-images");
    $responsiveImages = $media->responsive_images;
    rename("{$directory}/{$responsiveImages['thumb']['urls'][0]}", "{$directory}/test___thumb_999_823.jpg");
    $responsiveImages['thumb']['urls'] = ['test___thumb_999_823.jpg'];
    $media->responsive_images = $responsiveImages;
    $media->save();

    $files = responsiveFilesOnDisk($media);

    failTinyPlaceholders();

    expect(fn () => app(FileManipulator::class)->regenerateDerivedFiles($media, ['thumb']))
        ->toThrow(RuntimeException::class, 'placeholder failed');

    expect($media->fresh()->responsive_images)->toBe($responsiveImages)
        ->and(responsiveFilesOnDisk($media))->toBe($files);
});

it('removes its temporary directory when generating responsive images fails', function () {
    $temporaryDirectory = $this->getTempDirectory('responsive-temp');
    config()->set('media-library.temporary_directory_path', $temporaryDirectory);

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection();

    failTinyPlaceholders();

    expect(fn () => app(ResponsiveImageGenerator::class)->generateResponsiveImages($media))
        ->toThrow(RuntimeException::class, 'placeholder failed');

    expect(File::directories($temporaryDirectory))->toBe([]);
});
