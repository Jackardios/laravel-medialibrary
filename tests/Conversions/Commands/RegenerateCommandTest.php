<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\RegenerateMediaJob;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithConversion;

it('can regenerate all files', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestFilesDirectory('test.jpg'))->toMediaCollection('images');

    $derivedImage = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $createdAt = filemtime($derivedImage);

    unlink($derivedImage);

    $this->assertFileDoesNotExist($derivedImage);

    sleep(1);

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

    $existsCreatedAt = filemtime($derivedImageExists);

    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);

    $this->assertFileDoesNotExist($derivedMissingImage);

    sleep(1);

    // The DB still marks `thumb` as generated (only the file was removed), so `--verify-existence`
    // is required to detect the on-disk gap and regenerate it.
    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--verify-existence' => true,
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

    $existsCreatedAt = filemtime($derivedImageExists);

    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);

    $this->assertFileDoesNotExist($derivedMissingImage);

    sleep(1);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--verify-existence' => true,
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

    $existsCreatedAt = filemtime($derivedImageExists);
    $missingCreatedAt = filemtime($derivedMissingImage);

    unlink($derivedMissingImage);
    unlink($derivedMissingImageOriginal);

    $this->assertFileDoesNotExist($derivedMissingImage);
    $this->assertFileDoesNotExist($derivedMissingImageOriginal);

    sleep(1);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--verify-existence' => true,
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
    $createdAt = filemtime($conversion);

    sleep(1);

    // --verify-existence forces the on-disk check, which must resolve against `conversions_disk`.
    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--verify-existence' => true,
    ]);

    // The conversion already exists on the conversions disk, so onlyMissing must skip it.
    // Regression: the existence check used `disk` instead of `conversions_disk`, looked in the
    // wrong disk, never found the file, and needlessly regenerated every conversion.
    expect(filemtime($conversion))->toBe($createdAt);
});

it('regenerates missing conversions stored on a separate disk when regenerating only missing', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->storingConversionsOnDisk('secondMediaDisk')
        ->toMediaCollection();

    $conversion = $media->getPath('thumb');
    expect($conversion)->toBeFile();
    $createdAt = filemtime($conversion);

    unlink($conversion);
    $this->assertFileDoesNotExist($conversion);

    sleep(1);

    $this->artisan('media-library:regenerate', [
        '--only-missing' => true,
        '--verify-existence' => true,
    ]);

    expect($conversion)->toBeFile();
    expect(filemtime($conversion))->toBeGreaterThan($createdAt);
});

it('regenerates conversions the database marks as not generated when regenerating only missing', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");
    $createdAt = filemtime($thumb);

    // The file is still present, but the database no longer marks it as generated. By default
    // `--only-missing` trusts the column, so the conversion must be regenerated without any
    // storage existence check.
    $media->markAsConversionNotGenerated('thumb');

    sleep(1);

    $this->artisan('media-library:regenerate', ['--only-missing' => true]);

    expect(filemtime($thumb))->toBeGreaterThan($createdAt);
});

it('skips conversions the database marks as generated when regenerating only missing', function () {
    $media = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    $thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg");

    // Remove the file but keep the DB flag. The default (no --verify-existence) trusts the column,
    // so the conversion must be skipped and the file must stay missing.
    unlink($thumb);
    $this->assertFileDoesNotExist($thumb);

    $this->artisan('media-library:regenerate', ['--only-missing' => true]);

    $this->assertFileDoesNotExist($thumb);
});

it('dispatches a regenerate job per media when the configured queue connection is async', function () {
    $media1 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->preservingOriginal()
        ->toMediaCollection('images');

    $media2 = $this->testModelWithConversion
        ->addMedia($this->getTestFilesDirectory('test.jpg'))
        ->toMediaCollection('images');

    // An async connection means the command offloads to workers instead of running inline.
    config(['media-library.queue_connection_name' => 'redis']);

    Queue::fake();

    $this->artisan('media-library:regenerate');

    Queue::assertPushed(RegenerateMediaJob::class, 2);
    Queue::assertPushed(RegenerateMediaJob::class, fn (RegenerateMediaJob $job) => $job->connection === 'redis');
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
