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

it('keeps the files of a zip file prefix inside the zip', function (string $prefix, string $entryName) {
    $media = $this->testModel
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->withCustomProperties(['zip_filename_prefix' => $prefix])
        ->toMediaCollection();

    expect(zipEntryNames(MediaStream::create('my-media.zip')->addMedia($media)))->toBe([$entryName]);
})->with([
    'windows separators' => ['..\\..\\evil\\', 'evil/test.jpg'],
    'absolute path' => ['/etc/cron.d/', 'etc/cron.d/test.jpg'],
]);

it('gives files a unique name when their zip file prefixes only differ by a leading slash', function () {
    $media = collect(['/folder/', 'folder/'])->map(fn (string $prefix) => $this->testModel
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->withCustomProperties(['zip_filename_prefix' => $prefix])
        ->toMediaCollection());

    expect(zipEntryNames(MediaStream::create('my-media.zip')->addMedia($media)))->toBe(['folder/test.jpg', 'folder/test (1).jpg']);
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

it('gives every file a unique name once the zip has replaced the characters it does not allow', function () {
    $media = collect(['a:b.jpg', 'a_b.jpg', 'A*B.jpg', 'dir\\a|b.jpg'])->map(function (string $downloadName) {
        $media = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()
            ->withCustomProperties(['download_name' => $downloadName])
            ->toMediaCollection();

        return MediaWithDownloadName::find($media->id);
    });

    expect(zipEntryNames(MediaStream::create('my-media.zip')->addMedia($media)))
        ->toBe(['a_b.jpg', 'a_b (1).jpg', 'A_B (2).jpg', 'dir_a_b.jpg']);
});

it('names the files the same way every time the zip is streamed', function () {
    $mediaStream = MediaStream::create('my-media.zip')->addMedia(Media::all());

    expect(zipEntryNames($mediaStream))->toBe(['test.jpg', 'test (1).jpg', 'test (2).jpg'])
        ->and(zipEntryNames($mediaStream))->toBe(['test.jpg', 'test (1).jpg', 'test (2).jpg']);
});

it('zips every file with its exact contents', function () {
    $smallFile = (new TemporaryDirectory)->create()->path('small.txt');
    file_put_contents($smallFile, 'hello');
    $this->testModel->addMedia($smallFile)->toMediaCollection();

    ob_start();
    @MediaStream::create('my-media.zip')->addMedia(Media::all())->toResponse(request())->sendContent();
    $zipPath = (new TemporaryDirectory)->create()->path('media.zip');
    file_put_contents($zipPath, ob_get_clean());

    $zip = new ZipArchive;
    expect($zip->open($zipPath, ZipArchive::CHECKCONS))->toBeTrue()
        ->and($zip->getFromName('test (2).jpg'))->toBe(file_get_contents($this->getTestJpg()))
        ->and($zip->getFromName('small.txt'))->toBe('hello');
    $zip->close();
});

it('quotes the name of the zip in its response', function (string $zipName, string $contentDisposition) {
    expect(MediaStream::create($zipName)->toResponse(request())->headers->get('Content-Disposition'))->toBe($contentDisposition);
})->with([
    ['my-media.zip', 'attachment; filename="my-media.zip"'],
    ['my "media".zip', "attachment; filename=\"my 'media'.zip\"; filename*=utf-8''my%20%22media%22.zip"],
    ['media\\.zip', "attachment; filename=\"media_.zip\"; filename*=utf-8''media%5C.zip"],
    ['фото.zip', "attachment; filename=\"foto.zip\"; filename*=utf-8''%D1%84%D0%BE%D1%82%D0%BE.zip"],
]);
