<?php

use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Tests\TestSupport\TestUuidPathGenerator;

it('can save conversions on a separate disk', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->disk)->toEqual('public');
    expect($media->conversions_disk)->toEqual('secondMediaDisk');

    expect($media->getUrl())->toEqual("/media/{$media->id}/test.jpg");
    expect($media->getUrl('thumb'))->toEqual("/media2/{$media->id}/conversions/test-thumb.jpg");

    $originalFilePath = $media->getPath();

    $this->assertEquals(
        $this->getTempDirectory('media/1/test.jpg'),
        $originalFilePath
    );
    expect($originalFilePath)->toBeFile();

    $conversionsFilePath = $media->getPath('thumb');
    $this->assertEquals(
        $this->getTempDirectory('media2/1/conversions/test-thumb.jpg'),
        $conversionsFilePath
    );
    expect($conversionsFilePath)->toBeFile();
});

test('the responsive images will get saved on the same disk as the conversions', function () {
    $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($this->getTempDirectory('media2/1/responsive-images/test___thumb_50_41.jpg'))->toBeFile();
});

test('deleting media will also delete conversions on the separate disk', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->getPath('thumb'))->toBeFile();

    $media->delete();

    $this->assertFileDoesNotExist($media->getPath('thumb'));

    $originalFilePath = $media->getPath();
    $this->assertFileDoesNotExist($originalFilePath);
});

it('will store the conversion on the disk specified in on the media collection', function () {
    $media = $this->testModelWithConversionsOnOtherDisk
        ->addMedia($this->getTestJpg())
        ->toMediaCollection('thumb');

    $conversionsFilePath = $media->getPath('thumb');
    $this->assertEquals(
        $this->getTempDirectory('media2/1/conversions/test-thumb.jpg'),
        $conversionsFilePath
    );
    expect($conversionsFilePath)->toBeFile();
});

it('uses the globally configured conversions disk when no other disk is specified', function () {
    config()->set('media-library.conversions_disk_name', 'secondMediaDisk');

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->toMediaCollection();

    expect($media->disk)->toEqual('public');
    expect($media->conversions_disk)->toEqual('secondMediaDisk');

    expect($media->getPath('thumb'))->toBeFile();
    $this->assertEquals(
        $this->getTempDirectory('media2/1/conversions/test-thumb.jpg'),
        $media->getPath('thumb')
    );
});

it('falls back to the originals disk when the global conversions disk is empty', function () {
    config()->set('media-library.conversions_disk_name', null);

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->toMediaCollection();

    expect($media->conversions_disk)->toEqual($media->disk);
});

it('lets a per-call storingConversionsOnDisk override the global config', function () {
    config()->set('media-library.conversions_disk_name', 'public');

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->conversions_disk)->toEqual('secondMediaDisk');
});

it('lets a per-collection conversions disk override the global config', function () {
    config()->set('media-library.conversions_disk_name', 'public');

    $media = $this->testModelWithConversionsOnOtherDisk
        ->addMedia($this->getTestJpg())
        ->toMediaCollection('thumb');

    // The collection registers 'secondMediaDisk' via storeConversionsOnDisk(),
    // which must win over the global config value.
    expect($media->conversions_disk)->toEqual('secondMediaDisk');
});

test('Filesystem::removeResponsiveImages deletes responsive images stored on the separate conversions disk', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->disk)->toEqual('public');
    expect($media->conversions_disk)->toEqual('secondMediaDisk');

    $responsiveImagePath = $this->getTempDirectory("media2/{$media->id}/responsive-images/test___thumb_50_41.jpg");
    expect($responsiveImagePath)->toBeFile();

    app(Filesystem::class)->removeResponsiveImages($media, 'thumb');

    $this->assertFileDoesNotExist($responsiveImagePath);
});

test('DefaultFileRemover::removeResponsiveImages deletes responsive images stored on the separate conversions disk', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->conversions_disk)->toEqual('secondMediaDisk');

    $responsiveImagePath = $this->getTempDirectory("media2/{$media->id}/responsive-images/test___thumb_50_41.jpg");
    expect($responsiveImagePath)->toBeFile();

    app(DefaultFileRemover::class)->removeResponsiveImages($media, 'thumb');

    $this->assertFileDoesNotExist($responsiveImagePath);
});

it('uses the original disk for conversions of media without a conversions disk', function () {
    // Rows created outside the FileAdder can carry a null conversions_disk. Point the application's
    // default disk elsewhere, so that falling through to Storage::disk(null) is observable.
    config()->set('filesystems.default', 'secondMediaDisk');

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();
    Media::query()->whereKey($media->id)->update(['conversions_disk' => null]);
    $media = Media::find($media->id);

    expect($media->conversions_disk)->toBe('public');

    unlink($media->getPath('thumb'));

    app(FileManipulator::class)->regenerateDerivedFiles($media);

    expect($media->getPath('thumb'))->toBe($this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"))
        ->and($media->getPath('thumb'))->toBeFile()
        ->and($this->getTempDirectory("media2/{$media->id}"))->not->toBeDirectory();

    $media->delete();

    expect($this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"))->not->toBeFile();
});

it('builds the srcset of the original on the conversions disk', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->withResponsiveImages()
        ->toMediaCollection();

    $urls = $media->fresh()->getResponsiveImageUrls();

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        expect($url)->toStartWith("/media2/{$media->id}/responsive-images/")
            ->and($this->getTempDirectory('media2/'.Str::after($url, '/media2/')))->toBeFile();
    }
});

it('moves conversions stored on a separate disk when moving media on update', function () {
    config()->set('media-library.moves_media_on_update', true);
    config()->set('media-library.path_generator', TestUuidPathGenerator::class);

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestJpg())
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    $oldConversionPath = $media->getPath('thumb');
    expect($oldConversionPath)->toBeFile();

    $media->update(['uuid' => (string) Str::uuid()]);

    expect($media->getPath())->toBeFile()
        ->and($media->getPath('thumb'))->toBeFile()
        ->and($media->getPath('thumb'))->not->toBe($oldConversionPath)
        ->and($oldConversionPath)->not->toBeFile();
});
