<?php

use Illuminate\Support\Facades\Route;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\MediaStream;
use Spatie\MediaLibrary\Tests\TestSupport\TestMediaModel;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    foreach (range(1, 3) as $i) {
        $this
            ->testModel
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->toMediaCollection();
    }
});

it('can return a stream of media', function () {
    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    expect($zipStreamResponse->getMediaItems()->count())->toEqual(count(Media::all()));

    Route::get('stream-test', fn () => $zipStreamResponse);

    $response = $this->get('stream-test');

    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
});

it('can return a stream of multiple files with the same filename', function () {
    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'test.jpg');
    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'test (1).jpg');
    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'test (2).jpg');
});

it('will respect the filename set by getDownloadFilename method', function () {
    $zipStreamResponse = MediaStream::create('my-media.zip')
        ->addMedia(Media::find(1))
        ->addMedia(TestMediaModel::find(2))
        ->addMedia(TestMediaModel::find(2));

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'test.jpg');
    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'overriden_testing.jpg');
    $this->assertFileExistsInZip($temporaryDirectory->path('response.zip'), 'overriden_testing (1).jpg');
});

test('media can be added to it one by one', function () {
    $zipStreamResponse = MediaStream::create('my-media.zip')
        ->addMedia(Media::find(1))
        ->addMedia(Media::find(2));

    expect($zipStreamResponse->getMediaItems()->count())->toEqual(2);
});

test('an array of media can be added to it', function () {
    $zipStreamResponse = MediaStream::create('my-media.zip')
        ->addMedia([Media::find(1), Media::find(2)]);

    expect($zipStreamResponse->getMediaItems()->count())->toEqual(2);
});

test('media with zip file folder prefix property saved in correct zip folder', function () {
    $this->testModel
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->withCustomProperties([
            'zip_filename_prefix' => 'folder/subfolder/',
        ])
        ->toMediaCollection();

    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'test (2).jpg');

    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'folder/subfolder/test.jpg');
});

test('media with zip file folder prefix property saved in correct zip folder and correct suffix', function () {
    foreach (range(1, 2) as $i) {
        $this->testModel
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->toMediaCollection();
    }

    foreach (range(1, 2) as $i) {
        $this->testModel
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->withCustomProperties([
                'zip_filename_prefix' => 'folder/subfolder/',
            ])
            ->toMediaCollection();
    }

    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'test.jpg');
    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'test (1).jpg');
    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'test (2).jpg');

    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'folder/subfolder/test.jpg');
    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'folder/subfolder/test (1).jpg');
});

test('path traversal sequences in the zip file prefix property are stripped', function () {
    $this->testModel
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->withCustomProperties([
            'zip_filename_prefix' => '../../../etc/cron.d/',
        ])
        ->toMediaCollection();

    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileDoesntExistsInZip($temporaryDirectory->path('response.zip'), '../../../etc/cron.d/test.jpg');
    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'etc/cron.d/test.jpg');
});

test('media with zip file prefix property saved with correct prefix', function () {
    $this->testModel
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->withCustomProperties([
            'zip_filename_prefix' => 'just_a_string_prefix ',
        ])
        ->toMediaCollection();

    $zipStreamResponse = MediaStream::create('my-media.zip')->addMedia(Media::all());

    ob_start();
    @$zipStreamResponse->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();
    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.zip'), $content);

    $this->assertFileExistsInZipRecognizeFolder($temporaryDirectory->path('response.zip'), 'just_a_string_prefix test.jpg');
});

function zipEntryNames(MediaStream $mediaStream): array
{
    ob_start();
    @$mediaStream->toResponse(request())->sendContent();
    $content = ob_get_clean();

    $path = (new TemporaryDirectory)->create()->path('response.zip');
    file_put_contents($path, $content);

    $zip = new ZipArchive;
    $zip->open($path);

    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }

    $zip->close();

    return $names;
}

it('numbers duplicate file names without an extension', function () {
    $first = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('README')->toMediaCollection();
    $second = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('README')->toMediaCollection();

    expect(zipEntryNames(MediaStream::create('my-media.zip')->addMedia($first, $second)))->toBe(['README', 'README (1)']);
});

class MediaWithDownloadName extends Media
{
    protected $table = 'media';

    public function getDownloadFilename(): string
    {
        return $this->getCustomProperty('download_name');
    }
}

it('gives every file in the zip a unique name', function () {
    $media = collect(['a.jpg', 'a.jpg', 'a (1).jpg'])->map(function (string $downloadName) {
        $media = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()
            ->withCustomProperties(['download_name' => $downloadName])
            ->toMediaCollection();

        return MediaWithDownloadName::find($media->id);
    });

    expect(zipEntryNames(MediaStream::create('my-media.zip')->addMedia($media)))->toBe(['a.jpg', 'a (1).jpg', 'a (1) (1).jpg']);
});

it('names the files the same way every time the zip is streamed', function () {
    $mediaStream = MediaStream::create('my-media.zip')->addMedia(Media::all());

    expect(zipEntryNames($mediaStream))->toBe(['test.jpg', 'test (1).jpg', 'test (2).jpg'])
        ->and(zipEntryNames($mediaStream))->toBe(['test.jpg', 'test (1).jpg', 'test (2).jpg']);
});

it('reads no more of a local file than its size', function () {
    $zipStream = MediaStream::create('my-media.zip')->addMedia(Media::all());
    $output = fopen('php://memory', 'w+');
    $zipStream->useZipOptions(function (array &$options) use ($output) {
        $options['outputStream'] = $output;
        $options['sendHttpHeaders'] = false;
    });

    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();

    $zipStream->getZipStream();

    // Without the size, zipstream allocates 16 MiB for every read.
    expect(memory_get_peak_usage() - $memoryBefore)->toBeLessThan(4 * 1024 * 1024);

    rewind($output);
    $zipPath = (new TemporaryDirectory)->create()->path('media.zip');
    file_put_contents($zipPath, stream_get_contents($output));

    $this->assertFileExistsInZip($zipPath, 'test (2).jpg');
});
