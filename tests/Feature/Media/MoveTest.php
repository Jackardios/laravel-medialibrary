<?php

use Spatie\MediaLibrary\MediaCollections\Exceptions\MediaCannotBeUpdated;
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

it('refuses to move media to a model that is not saved yet', function () {
    $media = TestModel::create(['name' => 'test'])->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $anotherModel = new TestModel(['name' => 'another-test']);

    // The media would be lost if the model is never saved.
    expect(fn () => $media->move($anotherModel, 'images'))->toThrow(MediaCannotBeUpdated::class);

    $anotherModel->save();

    expect($media->fresh())->not->toBeNull()
        ->and($media->getPath())->toBeFile()
        ->and($anotherModel->getMedia('images'))->toHaveCount(0);
});
