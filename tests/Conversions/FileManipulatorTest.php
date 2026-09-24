<?php

use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\Conversions\Actions\PerformManipulationsAction;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\FileManipulator;

beforeEach(function () {
    $this->conversionName = 'test';
    $this->conversion = new Conversion($this->conversionName);
});

it('does not perform manipulations if not necessary', function () {
    $imageFile = $this->getTestJpg();
    $media = $this->testModelWithoutMediaConversions->addMedia($this->getTestJpg())->toMediaCollection();

    $conversionTempFile = (new PerformManipulationsAction)->execute(
        $media,
        $this->conversion->withoutManipulations(),
        $imageFile
    );

    expect($conversionTempFile)->toEqual($imageFile);
});

it('removes its temporary directory after regenerating the derived files', function () {
    $temporaryDirectory = $this->getTempDirectory('regenerate-temp');
    config()->set('media-library.temporary_directory_path', $temporaryDirectory);

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    unlink($thumb = $this->getMediaDirectory("{$media->id}/conversions/test-thumb.jpg"));

    app(FileManipulator::class)->regenerateDerivedFiles($media->fresh(), ['thumb']);

    expect($thumb)->toBeFile()
        ->and(File::directories($temporaryDirectory))->toBe([])
        ->and(File::allFiles($temporaryDirectory))->toBe([]);
});
