<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\MediaCannotBeUpdated;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\MediaLibrary\Tests\TestSupport\TestFileNamer;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestCustomMediaWithCustomKeyName;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

it('will rename the file if it is changed on the media object', function () {
    $testFile = $this->getTestFilesDirectory('test.jpg');

    $media = $this->testModel->addMedia($testFile)->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/test.jpg'));

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $this->assertFileDoesNotExist($this->getMediaDirectory($media->id.'/test.jpg'));
    $this->assertFileExists($this->getMediaDirectory($media->id.'/test-new-name.jpg'));
});

it('will rename the file with a custom model with custom key name', function () {
    config()->set('media-library.media_model', TestCustomMediaWithCustomKeyName::class);

    (new MediaLibraryServiceProvider(app()))->register()->boot();

    $this->setUpDatabaseCustomKeyName();

    $testFile = $this->getTestFilesDirectory('test.jpg');

    $media = $this->testModel->addMedia($testFile)->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->getKey().'/test.jpg'));

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $this->assertFileDoesNotExist($this->getMediaDirectory($media->getKey().'/test.jpg'));
    $this->assertFileExists($this->getMediaDirectory($media->getKey().'/test-new-name.jpg'));
});

it('will rename conversions', function () {
    $testFile = $this->getTestFilesDirectory('test.jpg');

    $media = $this->testModelWithConversion->addMedia($testFile)->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/conversions/test-thumb.jpg'));

    $media->file_name = 'test-new-name.jpg';

    $media->save();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/conversions/test-new-name-thumb.jpg'));
});

it('keeps valid file name when renaming with missing conversions', function () {
    $testFile = $this->getTestFilesDirectory('test.jpg');

    $media = $this->testModelWithConversion->addMedia($testFile)->toMediaCollection();

    $this->assertFileExists(
        $thumb_conversion = $this->getMediaDirectory($media->id.'/conversions/test-thumb.jpg')
    );

    unlink($thumb_conversion);

    $media->file_name = $new_filename = 'test-new-name.jpg';

    $media->save();

    // Reload attributes from the database
    $media = $media->fresh();

    expect($media->getPath())->toBeFile();
    expect($media->file_name)->toEqual($new_filename);
});

it('will rename responsive image files and rewrite the responsive_images json', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    $oldFileName = $media->responsive_images['thumb']['urls'][0];
    $oldResponsiveFile = $this->getMediaDirectory($media->id.'/responsive-images/'.$oldFileName);
    $this->assertFileExists($oldResponsiveFile);

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $media = $media->fresh();

    $newFileName = 'test-new-name'.substr($oldFileName, strrpos($oldFileName, '___'));

    $this->assertFileDoesNotExist($oldResponsiveFile);
    $this->assertFileExists($this->getMediaDirectory($media->id.'/responsive-images/'.$newFileName));

    foreach ($media->responsive_images as $properties) {
        foreach ($properties['urls'] ?? [] as $fileName) {
            expect($fileName)->toStartWith('test-new-name___');
        }
    }

    $srcset = $media->getSrcset('thumb');
    expect($srcset)->toContain($newFileName);
    expect($srcset)->not->toContain($oldFileName);
});

it('is a no-op when renaming media without responsive images', function () {
    $media = $this->testModel->addMedia($this->getTestFilesDirectory('test.jpg'))->toMediaCollection();

    expect($media->responsive_images)->toBeEmpty();

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $media = $media->fresh();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/test-new-name.jpg'));
    expect($media->responsive_images)->toBeEmpty();
});

it('rewrites the responsive_images json even when a responsive file is missing on disk', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    $oldFileName = $media->responsive_images['thumb']['urls'][0];
    unlink($this->getMediaDirectory($media->id.'/responsive-images/'.$oldFileName));

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $media = $media->fresh();

    $newFileName = 'test-new-name'.substr($oldFileName, strrpos($oldFileName, '___'));
    expect($media->responsive_images['thumb']['urls'][0])->toBe($newFileName);
});

it('preserves the base64svg placeholder through a rename', function () {
    $media = $this->testModelWithResponsiveImages
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    $responsiveImages = $media->responsive_images;
    $responsiveImages['thumb']['base64svg'] = $placeholder = 'data:image/svg+xml;base64,UExBQ0VIT0xERVI=';
    $media->responsive_images = $responsiveImages;
    $media->save();

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $media = $media->fresh();

    expect($media->responsive_images['thumb']['base64svg'])->toBe($placeholder);
    expect($media->responsive_images['thumb']['urls'][0])->toStartWith('test-new-name___');
});

it('names renamed responsive images the way the file namer does', function () {
    config()->set('media-library.file_namer', TestFileNamer::class);

    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $media->file_name = 'renamed.jpg';
    $media->save();

    $urls = $media->fresh()->responsive_images['media_library_original']['urls'];

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $fileName) {
        expect($fileName)->toStartWith('prefix_renamed_suffix___media_library_original_')
            ->and($this->getMediaDirectory("{$media->id}/responsive-images/{$fileName}"))->toBeFile();
    }
});

it('keeps the name of a responsive image it could not move', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $directory = $this->getMediaDirectory("{$media->id}/responsive-images");
    $blocked = $media->responsive_images['media_library_original']['urls'][0];

    // A directory where the renamed file should go makes the move fail.
    mkdir("{$directory}/renamed".substr($blocked, strrpos($blocked, '___')));

    $media->file_name = 'renamed.jpg';
    $media->save();

    $urls = $media->fresh()->responsive_images['media_library_original']['urls'];

    expect($urls[0])->toBe($blocked);

    foreach ($urls as $fileName) {
        expect("{$directory}/{$fileName}")->toBeFile();
    }
});

it('does not rename the media when its file cannot be moved', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection();

    // A directory where the renamed file should go makes the move fail.
    mkdir($this->getMediaDirectory("{$media->id}/renamed.jpg"));

    $media->file_name = 'renamed.jpg';

    expect(fn () => $media->save())->toThrow(MediaCannotBeUpdated::class);

    expect($media->fresh()->file_name)->toBe('test.jpg')
        ->and($media->fresh()->getPath())->toBeFile();
});

class TestModelWithConversionsOfTwoCollections extends TestModel
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(50)->nonQueued();
        $this->addMediaConversion('banner')->width(80)->performOnCollections('banners')->nonQueued();
    }
}

class DiskRecordingExistsChecks extends FilesystemAdapter
{
    public static array $checked = [];

    public function exists($path)
    {
        static::$checked[] = $path;

        return parent::exists($path);
    }
}

it('renames the conversions of its collection without looking the media up again', function () {
    $media = TestModelWithConversionsOfTwoCollections::first()->addMedia($this->getTestJpg())->toMediaCollection();

    $disk = Storage::disk('public');
    Storage::set('public', new DiskRecordingExistsChecks($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()));
    DiskRecordingExistsChecks::$checked = [];

    DB::enableQueryLog();

    $media->file_name = 'renamed.jpg';
    $media->save();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query) => str_starts_with($query, 'select')))->toBeEmpty()
        ->and(DiskRecordingExistsChecks::$checked)->toContain("{$media->id}/conversions/test-thumb.jpg")
        ->and(DiskRecordingExistsChecks::$checked)->not->toContain("{$media->id}/conversions/test-banner.jpg")
        ->and($this->getMediaDirectory("{$media->id}/conversions/renamed-thumb.jpg"))->toBeFile();
});
