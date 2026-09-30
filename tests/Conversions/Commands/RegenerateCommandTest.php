<?php

use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\RegenerateMediaJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithConversion;

it('can regenerate all files', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestFilesDirectory('test.jpg'))->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    // Backdate the existing conversion so the regenerated file is guaranteed a
    // newer mtime, without waiting out filemtime()'s one-second granularity.
    touch($derivedImage, time() - 5);
    $createdAt = filemtime($derivedImage);

    unlink($derivedImage);

    $this->assertFileDoesNotExist($derivedImage);

    $this->artisan('media-library:regenerate');

    expect($derivedImage)->toBeFile();
    expect(filemtime($derivedImage))->toBeGreaterThan($createdAt);
});

it('can regenerate only missing files', function () {
    $mediaExists = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $mediaMissing = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.png'))
        ->toMediaCollection('images');

    $derivedImageExists = $this->getMediaDirectory("{$mediaExists->id}/conversions/test-thumb.jpg");

    $derivedMissingImage = $this->getMediaDirectory("{$mediaMissing->id}/conversions/test-thumb.jpg");

    touch($derivedImageExists, time() - 5);
    $existsCreatedAt = filemtime($derivedImageExists);

    // Backdate the existing conversion so the regenerated file is guaranteed a
    // newer mtime, without waiting out filemtime()'s one-second granularity.
    touch($derivedMissingImage, time() - 5);
    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);

    $this->assertFileDoesNotExist($derivedMissingImage);

    // The DB still marks `thumb` as generated (only the file was removed): the disk decides.
    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
    ]);

    expect($derivedMissingImage)->toBeFile();

    expect(filemtime($derivedImageExists))->toBe($existsCreatedAt);

    expect(filemtime($derivedMissingImage))->toBeGreaterThan($missingCreatedAt);
});

it('can regenerate missing files queued', function () {
    $mediaExists = $this
        ->testModelWithConversionQueued
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $mediaMissing = $this
        ->testModelWithConversionQueued
        ->addMedia($this->getTestFilesDirectory('test.png'))
        ->toMediaCollection('images');

    $derivedImageExists = $this->getMediaDirectory("{$mediaExists->id}/conversions/test-thumb.jpg");

    $derivedMissingImage = $this->getMediaDirectory("{$mediaMissing->id}/conversions/test-thumb.jpg");

    touch($derivedImageExists, time() - 5);
    $existsCreatedAt = filemtime($derivedImageExists);

    // Backdate the existing conversion so the regenerated file is guaranteed a
    // newer mtime, without waiting out filemtime()'s one-second granularity.
    touch($derivedMissingImage, time() - 5);
    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);

    $this->assertFileDoesNotExist($derivedMissingImage);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
    ]);

    expect($derivedMissingImage)->toBeFile();

    expect(filemtime($derivedImageExists))->toBe($existsCreatedAt);

    expect(filemtime($derivedMissingImage))->toBeGreaterThan($missingCreatedAt);
});

it('can regenerate all files of named conversions', function () {
    $media = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedMissingImage = $this->getMediaDirectory("{$media->id}/conversions/test-keep_original_format.jpg");

    unlink($derivedImage);
    unlink($derivedMissingImage);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedMissingImage);

    $this->artisan('media-library:regenerate', [
        '--only' => 'thumb',
    ]);

    expect($derivedImage)->toBeFile();
    $this->assertFileDoesNotExist($derivedMissingImage);
});

it('can regenerate only missing files of named conversions', function () {
    $mediaExists = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $mediaMissing = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.png'))
        ->toMediaCollection('images');

    $derivedImageExists = $this->getMediaDirectory("{$mediaExists->id}/conversions/test-thumb.jpg");
    $derivedMissingImage = $this->getMediaDirectory("{$mediaMissing->id}/conversions/test-thumb.jpg");
    $derivedMissingImageOriginal = $this->getMediaDirectory("{$mediaMissing->id}/conversions/test-keep_original_format.png");

    touch($derivedImageExists, time() - 5);
    $existsCreatedAt = filemtime($derivedImageExists);
    // Backdate the existing conversion so the regenerated file is guaranteed a
    // newer mtime, without waiting out filemtime()'s one-second granularity.
    touch($derivedMissingImage, time() - 5);
    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);
    unlink($derivedMissingImageOriginal);

    $this->assertFileDoesNotExist($derivedMissingImage);
    $this->assertFileDoesNotExist($derivedMissingImageOriginal);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--only' => 'thumb',
    ]);

    expect($derivedMissingImage)->toBeFile();
    $this->assertFileDoesNotExist($derivedMissingImageOriginal);
    expect(filemtime($derivedImageExists))->toBe($existsCreatedAt);
    expect(filemtime($derivedMissingImage))->toBeGreaterThan($missingCreatedAt);
});

it('can regenerate files by media ids', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);

    $this->artisan('media-library:regenerate', ['--ids' => [2]]);

    $this->assertFileDoesNotExist($derivedImage);
    expect($derivedImage2)->toBeFile();
});

it('can regenerate files by comma separated media ids', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);

    $this->artisan('media-library:regenerate', ['--ids' => ['1,2']]);

    expect($derivedImage)->toBeFile();
    expect($derivedImage2)->toBeFile();
});

it('can regenerate files even if there are files missing', function () {
    $media = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    unlink($this->getMediaDirectory($media->id.'/test.jpg'));

    $this->artisan('media-library:regenerate')->assertExitCode(0);
});

it('can regenerate responsive images', function () {
    $media = $this
        ->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    $responsiveImages = glob($this->getMediaDirectory($media->id.'/responsive-images/*'));

    array_map('unlink', $responsiveImages);

    $this->artisan('media-library:regenerate', ['--with-responsive-images' => true])->assertExitCode(0);

    foreach ($responsiveImages as $image) {
        expect($image)->toBeFile();
    }
});

it('does not generate responsive images of the original for media that never had them', function (array $options) {
    // Only the `thumb` conversion has responsive images.
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection();

    expect($media->fresh()->responsive_images)->toHaveKey('thumb')->not->toHaveKey('media_library_original');

    $this->artisan('media-library:regenerate', ['--with-responsive-images' => true, ...$options])->assertExitCode(0);

    expect($media->fresh()->responsive_images)->toHaveKey('thumb')->not->toHaveKey('media_library_original');
})->with([
    'inline' => [[]],
    'queue all' => [['--queue-all' => true]],
    'trusting the database' => [['--only-missing' => true, '--trust-database' => true]],
]);

it('can regenerate files by starting from id', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);

    $this->artisan('media-library:regenerate', ['--starting-from-id' => $media2->getKey()]);

    $this->assertFileDoesNotExist($derivedImage);
    expect($derivedImage2)->toBeFile();
});

it('can regenerate files starting after the provided id', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);

    $this->artisan('media-library:regenerate', [
        '--starting-from-id' => $media->getKey(),
        '--exclude-starting-id' => true,
    ]);

    $this->assertFileDoesNotExist($derivedImage);
    expect($derivedImage2)->toBeFile();
});

it('can regenerate files starting after the provided id with shortcut', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);

    $this->artisan('media-library:regenerate', [
        '--starting-from-id' => $media->getKey(),
        '-X' => true,
    ]);

    $this->assertFileDoesNotExist($derivedImage);
    expect($derivedImage2)->toBeFile();
});

it('can regenerate files starting from id with model type', function () {
    $media = $this->testModelWithConversionsOnOtherDisk
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media3 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $derivedImage2 = $this->getMediaDirectory("{$media2->id}/conversions/test-thumb.jpg");
    $derivedImage3 = $this->getMediaDirectory("{$media3->id}/conversions/test-thumb.jpg");

    unlink($derivedImage);
    unlink($derivedImage2);
    unlink($derivedImage3);

    $this->assertFileDoesNotExist($derivedImage);
    $this->assertFileDoesNotExist($derivedImage2);
    $this->assertFileDoesNotExist($derivedImage3);

    $this->artisan('media-library:regenerate', [
        '--starting-from-id' => $media->getKey(),
        'modelType' => TestModelWithConversion::class,
    ]);

    $this->assertFileDoesNotExist($derivedImage);
    expect($derivedImage2)->toBeFile();
    expect($derivedImage3)->toBeFile();
});

it('can set updated_at column when regenerating', function () {
    $this->travelTo('2020-01-01 00:00:00');
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $this->travelBack();

    $this->artisan('media-library:regenerate');

    $media->refresh();

    expect($media->updated_at)->toBeGreaterThanOrEqual(now()->subSeconds(5));
});

it('can force queue non-queued conversions', function () {
    Queue::fake();

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    unlink($thumbConversion = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"));

    $this->artisan('media-library:regenerate', ['--queue-all' => true]);

    $this->assertFileDoesNotExist($this->getMediaDirectory($thumbConversion));

    // Fork semantics: --queue-all dispatches one RegenerateMediaJob per media (the whole media is the unit of work).
    Queue::assertPushed(RegenerateMediaJob::class);
});

it('queues jobs that look for missing conversions on the disk unless the database is trusted', function (bool $trustDatabase, bool $regenerated) {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    // The thumb stays marked as generated, but its file is gone.
    unlink($thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"));

    $keptConversion = $this->getMediaDirectory("{$media->id}/conversions/test-keep_original_format.jpg");
    expect($keptConversion)->toBeFile();
    touch($keptConversion, time() - 3600);

    Queue::fake();

    $this->artisan('media-library:regenerate', [
        '--queue-all' => true,
        '--only-missing' => true,
        '--trust-database' => $trustDatabase,
    ]);

    Queue::assertPushed(RegenerateMediaJob::class, 1);

    Queue::pushed(RegenerateMediaJob::class)->first()->handle(app(FileManipulator::class));

    clearstatcache();

    expect(file_exists($thumb))->toBe($regenerated)
        ->and(filemtime($keptConversion))->toBeLessThan(time() - 60);
})->with([
    'checking the disk' => [false, true],
    'trusting the database' => [true, false],
]);

it('skips existing conversions stored on a separate disk when regenerating only missing', function () {
    // The original lives on `public`, the conversions on `secondMediaDisk` (disk !== conversions_disk).
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    expect($media->disk)->toBe('public');
    expect($media->conversions_disk)->toBe('secondMediaDisk');

    $conversion = $media->getPath('thumb');
    expect($conversion)->toBeFile();
    touch($conversion, time() - 5);
    $createdAt = filemtime($conversion);

    // The on-disk check must resolve against `conversions_disk`.
    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
    ]);

    // The conversion already exists on the conversions disk, so onlyMissing must skip it.
    expect(filemtime($conversion))->toBe($createdAt);
});

it('regenerates missing conversions stored on a separate disk when regenerating only missing', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    $conversion = $media->getPath('thumb');
    expect($conversion)->toBeFile();
    touch($conversion, time() - 5);
    $createdAt = filemtime($conversion);

    unlink($conversion);
    $this->assertFileDoesNotExist($conversion);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
    ]);

    expect($conversion)->toBeFile();
    expect(filemtime($conversion))->toBeGreaterThan($createdAt);
});

it('does not regenerate an existing conversion the database does not mark when regenerating only missing', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    $media->markAsConversionNotGenerated('thumb');

    $this->artisan('media-library:regenerate', ['--only-missing' => true])->assertSuccessful();

    clearstatcache();
    expect(filemtime($thumb))->toBe($createdAt);
});

it('regenerates conversions the database does not mark when regenerating only missing and trusting the database', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    // The file is still present, but the database no longer marks it as generated.
    $media->markAsConversionNotGenerated('thumb');

    $this->artisan('media-library:regenerate', ['--only-missing' => true, '--trust-database' => true]);

    clearstatcache();
    expect(filemtime($thumb))->toBeGreaterThan($createdAt)
        ->and($media->fresh()->hasGeneratedConversion('thumb'))->toBeTrue();
});

it('skips conversions the database marks as generated when regenerating only missing and trusting the database', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");

    // Remove the file but keep the DB flag: without asking the disk the conversion looks generated.
    unlink($thumb);
    $this->assertFileDoesNotExist($thumb);

    $this->artisan('media-library:regenerate', ['--only-missing' => true, '--trust-database' => true]);

    $this->assertFileDoesNotExist($thumb);
});

it('regenerates only the responsive images when trusting the database finds no missing conversion', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    $responsiveImages = $this->getMediaDirectory("{$media->id}/responsive-images");
    File::deleteDirectory($responsiveImages);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--trust-database' => true,
        '--with-responsive-images' => true,
    ]);

    clearstatcache();
    expect(filemtime($thumb))->toBe($createdAt)
        ->and(File::files($responsiveImages))->not->toBeEmpty();
});

it('regenerates a conversion whose file name changed since it was generated when regenerating only missing', function () {
    $model = RegenerateTestModelWithFormat::first();
    RegenerateTestModelWithFormat::$format = 'jpg';

    $media = $model->addMedia($this->getTestFilesDirectory('test.jpg'))->toMediaCollection();
    expect($this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"))->toBeFile();

    // The conversion now produces a webp: the database still marks `thumb` as generated.
    RegenerateTestModelWithFormat::$format = 'webp';

    $this->artisan('media-library:regenerate', ['--only-missing' => true]);

    expect($this->getMediaDirectory("{$media->id}/conversions/test-thumb.webp"))->toBeFile();
});

it('dispatches a regenerate job per media on the configured queue connection when queueing all', function () {
    $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    config(['media-library.queue_connection_name' => 'redis']);

    Queue::fake();

    $this->artisan('media-library:regenerate', ['--queue-all' => true]);

    Queue::assertPushed(RegenerateMediaJob::class, 2);
    Queue::assertPushed(RegenerateMediaJob::class, fn (RegenerateMediaJob $job) => $job->connection === 'redis');
});

it('performs non-queued conversions inline when the configured queue connection is async', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    unlink($thumb);

    config(['media-library.queue_connection_name' => 'redis']);

    Queue::fake();

    $this->artisan('media-library:regenerate')->assertSuccessful();

    Queue::assertNotPushed(RegenerateMediaJob::class);
    expect($thumb)->toBeFile();
});

it('regenerates inline without dispatching jobs when the queue connection is sync', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    unlink($thumb);
    $this->assertFileDoesNotExist($thumb);

    Queue::fake();

    // The default test connection is sync, so the command must regenerate inline.
    $this->artisan('media-library:regenerate');

    Queue::assertNothingPushed();
    expect($thumb)->toBeFile();
});

it('dispatches jobs when --queue-connection points at an async connection', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    Queue::fake();

    $this->artisan('media-library:regenerate', ['--queue-connection' => 'redis']);

    Queue::assertPushed(RegenerateMediaJob::class, 1);
});

it('regenerates derived files when the queued regenerate job is handled', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");

    unlink($thumb);
    $this->assertFileDoesNotExist($thumb);

    (new RegenerateMediaJob($media))->handle(app(FileManipulator::class));

    expect($thumb)->toBeFile();
});

it('still regenerates responsive images when a conversion fails', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    // Reload so the persisted `responsive_images` is present on the instance.
    $media->refresh();

    // A failing conversion must not prevent responsive images from being (re)generated.
    $responsiveGenerator = $this->mock(ResponsiveImageGenerator::class);
    $responsiveGenerator->shouldReceive('generateResponsiveImages')->once();

    $fileManipulator = new class extends FileManipulator
    {
        protected function performConversionsOnCopiedFile(
            ConversionCollection $conversions,
            Media $media,
            string $copiedOriginalFile
        ): void {
            throw new RuntimeException('conversion failed');
        }
    };

    expect(fn () => $fileManipulator->regenerateDerivedFiles($media, [], false, true))
        ->toThrow(RuntimeException::class, 'conversion failed');
});

it('reuses the downloaded original for responsive images instead of downloading it again', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection();

    $media->refresh();

    $captured = ['baseImage' => false, 'existedDuringCall' => false];

    $responsiveGenerator = $this->mock(ResponsiveImageGenerator::class);
    $responsiveGenerator->shouldReceive('generateResponsiveImages')
        ->once()
        ->andReturnUsing(function (Media $media, ?string $baseImage = null) use (&$captured) {
            $captured['baseImage'] = $baseImage;
            $captured['existedDuringCall'] = $baseImage !== null && file_exists($baseImage);
        });

    app(FileManipulator::class)->regenerateDerivedFiles($media, [], false, true);

    // A non-null base image that exists at call time proves the already-downloaded original was
    // handed to the responsive generator instead of being fetched from the disk a second time.
    expect($captured['baseImage'])->toBeString()
        ->and($captured['existedDuringCall'])->toBeTrue();
});

it('persists regenerated conversions with a single database write per media', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection();

    // Force both registered conversions to be regenerated.
    $media->markAsConversionNotGenerated('thumb');
    $media->markAsConversionNotGenerated('keep_original_format');
    $media->save();

    $media = $media->fresh();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    app(FileManipulator::class)->regenerateDerivedFiles($media);

    DB::connection()->disableQueryLog();

    $updateQueries = collect(DB::connection()->getQueryLog())
        ->filter(fn (array $entry) => str_starts_with(strtolower(ltrim($entry['query'])), 'update'));

    // One save for all conversions, not one per conversion.
    expect($updateQueries)->toHaveCount(1);
});

it('fails when a media could not be regenerated', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $this->app->instance(FileManipulator::class, new class extends FileManipulator
    {
        public function createDerivedFiles(
            Media $media,
            array $onlyConversionNames = [],
            bool $onlyMissing = false,
            bool $withResponsiveImages = false,
            bool $queueAll = false,
        ): void {
            throw new RuntimeException('conversion failed');
        }
    });

    $this->artisan('media-library:regenerate')
        ->expectsOutputToContain("Media id {$media->id}: `conversion failed`")
        ->assertFailed();
});

it('restores the mark of an existing conversion when the regenerate job checks the disk', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    $media->markAsConversionNotGenerated('thumb');

    app(FileManipulator::class)->regenerateDerivedFiles($media->fresh(), ['thumb'], onlyMissing: true, verifyExistence: true);

    clearstatcache();
    expect(filemtime($thumb))->toBe($createdAt)
        ->and($media->fresh()->hasGeneratedConversion('thumb'))->toBeTrue();
});

class RegenerateTestModelWithFormat extends TestModel
{
    public static string $format = 'jpg';

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(20)->format(static::$format)->nonQueued();
    }
}

it('recognises existing conversions when the disk root is not a prefix of their path', function () {
    // On Windows the local adapter joins the root with `\`, and a root configured with a trailing
    // `/` is then no prefix of the absolute path the disk reports.
    config()->set('filesystems.disks.public.root', $this->getMediaDirectory().'/');
    config()->set('filesystems.disks.public.directory_separator', '\\');

    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    // The adapter itself writes with `/`, only the reported path uses `\`.
    $thumb = str_replace('\\', '/', $media->getPath('thumb'));
    expect($thumb)->toBeFile();
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    $this->artisan('media-library:regenerate', ['--only-missing' => true]);

    clearstatcache();
    expect(filemtime($thumb))->toBe($createdAt);
});

it('performs the deferred conversions of each media before the command ends', function () {
    // Laravel runs deferred callbacks after a command only when it succeeds: one media that
    // fails must not keep the deferred conversions of the others from being performed.
    $media = $this->testModelWithConversionDeferred
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    app(DeferredCallbackCollection::class)->invoke();
    File::delete($media->getPath('thumb'));

    $this->artisan('media-library:regenerate');

    expect($media->getPath('thumb'))->toBeFile()
        ->and(app(DeferredCallbackCollection::class)->count())->toBe(0);
});

it('regenerates only the responsive images of the named conversions when trusting the database finds no missing conversion', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->withResponsiveImages()
        ->toMediaCollection('images');

    File::delete($media->getPath('keep_original_format'));
    $media->markAsConversionNotGenerated('keep_original_format');
    $media->save();

    $this->artisan('media-library:regenerate', [
        '--only' => ['thumb'],
        '--only-missing' => true,
        '--trust-database' => true,
        '--with-responsive-images' => true,
    ]);

    expect($media->getPath('keep_original_format'))->not->toBeFile();
});

it('regenerates a media stored without responsive images', function (array $options) {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection('images');
    DB::table('media')->where('id', $media->id)->update(['responsive_images' => 'null']);
    unlink($media->getPath('thumb'));

    $this->artisan('media-library:regenerate', ['--with-responsive-images' => true, ...$options])->assertSuccessful();

    expect($media->getPath('thumb'))->toBeFile();
})->with([
    'in the command' => [[]],
    'queued per media' => [['--queue-all' => true]],
]);

it('reports a deferred conversion that fails', function () {
    $media = $this->testModelWithConversionDeferred
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    app(DeferredCallbackCollection::class)->invoke();
    file_put_contents($media->getPath(), 'not an image');

    $this->artisan('media-library:regenerate')
        ->expectsOutputToContain("Media id {$media->id}:")
        ->assertFailed();
});

it('leaves other deferred callbacks to the end of the command', function () {
    $failingMedia = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');
    file_put_contents($failingMedia->getPath(), 'not an image');

    $media = $this->testModelWithConversionDeferred
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');
    app(DeferredCallbackCollection::class)->invoke();
    File::delete($media->getPath('thumb'));

    $invoked = false;
    defer(function () use (&$invoked) {
        $invoked = true;
    });

    // Laravel runs deferred callbacks after a command only when it succeeds.
    $this->artisan('media-library:regenerate')->assertFailed();

    expect($invoked)->toBeFalse()
        ->and($media->getPath('thumb'))->toBeFile();
});

it('performs the deferred conversions when queueing the others fails', function () {
    $model = RegenerateTestModelWithDeferredAndQueuedConversions::create(['name' => 'test']);
    $media = $model->addMedia($this->getTestFilesDirectory('test.jpg'))->toMediaCollection();
    app(DeferredCallbackCollection::class)->invoke();
    File::delete($media->getPath('deferred'));

    config()->set('media-library.queue_connection_name', 'missing');

    $this->artisan('media-library:regenerate')
        ->expectsOutputToContain("Media id {$media->id}:")
        ->assertFailed();

    expect($media->getPath('deferred'))->toBeFile();
});

class RegenerateTestModelWithDeferredAndQueuedConversions extends TestModel
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('deferred')->width(20)->deferred();
        $this->addMediaConversion('queued')->width(20)->queued();
    }
}

it('refuses a starting id that is not a whole number', function (string $startingFromId) {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $media->getPath('thumb');
    touch($thumb, time() - 5);
    $createdAt = filemtime($thumb);

    $this->artisan('media-library:regenerate', ['--starting-from-id' => $startingFromId])
        ->expectsOutputToContain('--starting-from-id')
        ->assertFailed();

    clearstatcache();
    expect(filemtime($thumb))->toBe($createdAt);
})->with(['abc', '1a', '-1', '']);
