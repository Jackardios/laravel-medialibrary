<?php

use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\DiskDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\Exceptions\MimeTypeNotAllowed;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

class RecordingDownloader implements Downloader
{
    public static ?string $temporaryFile = null;

    public function getTempFile(string $url): string
    {
        self::$temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');

        copy(test()->getTestJpg(), self::$temporaryFile);

        return self::$temporaryFile;
    }
}

class TestModelAcceptingOnlyPdfs extends TestModel
{
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('default')->acceptsMimeTypes(['application/pdf']);
    }
}

/**
 * The temporary files holding the given contents that the media library left behind.
 */
function leftTemporaryFiles(string $contents): array
{
    return array_values(array_filter(
        glob(sys_get_temp_dir().'/media-library*') ?: [],
        fn (string $path) => is_file($path) && file_get_contents($path) === $contents,
    ));
}

beforeEach(function () {
    config()->set('media-library.media_downloader', RecordingDownloader::class);
});

it('removes the downloaded file once it was added', function (bool $preservingOriginal) {
    $this->testModel->addMediaFromUrl('https://example.com/image.jpg')
        ->preservingOriginal($preservingOriginal)
        ->toMediaCollection();

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
})->with(['moving the file' => false, 'preserving the original' => true]);

it('removes the downloaded file when its mime type is not allowed', function () {
    expect(fn () => $this->testModel->addMediaFromUrl('https://example.com/image.jpg', 'application/pdf'))
        ->toThrow(MimeTypeNotAllowed::class);

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
});

it('removes the downloaded file when it is too big', function () {
    config()->set('media-library.max_file_size', 10);

    expect(fn () => $this->testModel->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection())
        ->toThrow(FileIsTooBig::class);

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
});

it('removes the downloaded file when adding it to a disk that does not exist', function () {
    expect(fn () => $this->testModel->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection('default', 'missing-disk'))
        ->toThrow(DiskDoesNotExist::class);

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
});

it('removes the downloaded file when the collection does not accept it', function () {
    $model = TestModelAcceptingOnlyPdfs::first();

    expect(fn () => $model->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection())
        ->toThrow(FileUnacceptableForCollection::class);

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
});

it('removes the downloaded file when the collection of a model being created does not accept it', function () {
    $model = new TestModelAcceptingOnlyPdfs(['name' => 'new']);

    $model->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection();

    expect(fn () => $model->save())->toThrow(FileUnacceptableForCollection::class);

    expect(RecordingDownloader::$temporaryFile)->not->toBeFile();
});

it('removes the decoded file when its mime type is not allowed', function () {
    $contents = file_get_contents($this->getTestJpg()).random_bytes(16);

    expect(fn () => $this->testModel->addMediaFromBase64(base64_encode($contents), 'application/pdf'))
        ->toThrow(MimeTypeNotAllowed::class);

    expect(leftTemporaryFiles($contents))->toBe([]);
});

it('removes the file holding a string when it is not added', function () {
    $contents = 'text '.bin2hex(random_bytes(16));

    expect(fn () => $this->testModel->addMediaFromString($contents)->toMediaCollection('default', 'missing-disk'))
        ->toThrow(DiskDoesNotExist::class);

    expect(leftTemporaryFiles($contents))->toBe([]);
});

it('removes the file holding a stream once it was added', function () {
    $contents = 'text '.bin2hex(random_bytes(16));

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $contents);
    rewind($stream);

    $this->testModel->addMediaFromStream($stream)->preservingOriginal()->toMediaCollection();

    expect(leftTemporaryFiles($contents))->toBe([]);
});

it('adds the other media of an unsaved model when one is not accepted, and removes every downloaded file', function () {
    $model = new TestModelAcceptingOnlyPdfs(['name' => 'unsaved']);

    $model->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection();
    $rejectedFile = RecordingDownloader::$temporaryFile;
    $model->addMediaFromUrl('https://example.com/image.jpg')->toMediaCollection('other');

    expect(fn () => $model->save())->toThrow(FileUnacceptableForCollection::class);

    expect($rejectedFile)->not->toBeFile()
        ->and(RecordingDownloader::$temporaryFile)->not->toBeFile()
        ->and($model->getMedia('other'))->toHaveCount(1);
});
