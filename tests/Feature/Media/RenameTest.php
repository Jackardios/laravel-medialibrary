<?php

it('will rename the file if it is changed on the media object', function () {
    $testFile = $this->getTestFilesDirectory('test.jpg');

    $media = $this->testModel->addMedia($testFile)->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/test.jpg'));

    $media->file_name = 'test-new-name.jpg';
    $media->save();

    $this->assertFileDoesNotExist($this->getMediaDirectory($media->id.'/test.jpg'));
    $this->assertFileExists($this->getMediaDirectory($media->id.'/test-new-name.jpg'));
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
