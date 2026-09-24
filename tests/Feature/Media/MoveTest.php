<?php

use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

it('can move media from one model to another', function () {
    $model = TestModel::create(['name' => 'test']);

    $media = $model
        ->addMedia($this->getTestJpg())
        ->usingName('custom-name')
        ->withCustomProperties(['custom-property-name' => 'custom-property-value'])
        ->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/test.jpg'));

    $anotherModel = TestModel::create(['name' => 'another-test']);

    $movedMedia = $media->move($anotherModel, 'images');

    expect($model->getMedia('default'))->toHaveCount(0);
    $this->assertFileDoesNotExist($this->getMediaDirectory($media->id.'/test.jpg'));

    expect($anotherModel->getMedia('images'))->toHaveCount(1);
    $this->assertFileExists($this->getMediaDirectory($movedMedia->id.'/test.jpg'));
    expect($anotherModel->id)->toEqual($movedMedia->model->id);
    expect('custom-name')->toEqual($movedMedia->name);
    expect('custom-property-value')->toEqual($movedMedia->getCustomProperty('custom-property-name'));
});

it('can move media from one model to another on a specific disk', function () {
    $diskName = 'secondMediaDisk';

    $model = TestModel::create(['name' => 'test']);

    $media = $model
        ->addMedia($this->getTestJpg())
        ->usingName('custom-name')
        ->withCustomProperties(['custom-property-name' => 'custom-property-value'])
        ->toMediaCollection();

    $this->assertFileExists($this->getMediaDirectory($media->id.'/test.jpg'));

    $anotherModel = TestModel::create(['name' => 'another-test']);

    $movedMedia = $media->move($anotherModel, 'images', $diskName);

    expect($model->getMedia('default'))->toHaveCount(0);
    $this->assertFileDoesNotExist($this->getMediaDirectory($media->id.'/test.jpg'));

    expect($anotherModel->getMedia('images'))->toHaveCount(1);
    $this->assertFileExists($this->getTempDirectory('media2').'/'.$movedMedia->id.'/test.jpg');
    expect('images')->toEqual($movedMedia->collection_name);
    expect($diskName)->toEqual($movedMedia->disk);
    expect($anotherModel->id)->toEqual($movedMedia->model->id);
    expect('custom-name')->toEqual($movedMedia->name);
    expect('custom-property-value')->toEqual($movedMedia->getCustomProperty('custom-property-name'));
});

it('can move media to a model that is saved afterwards', function () {
    $media = TestModel::create(['name' => 'test'])->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $anotherModel = new TestModel(['name' => 'another-test']);
    $media->move($anotherModel, 'images');
    $anotherModel->save();

    $movedMedia = $anotherModel->getFirstMedia('images');

    expect($movedMedia->getPath())->toBeFile()
        ->and(file_get_contents($movedMedia->getPath()))->toBe(file_get_contents($this->getTestJpg()))
        ->and($movedMedia->file_name)->toBe('test.jpg')
        ->and($media->getPath())->not->toBeFile();
});
