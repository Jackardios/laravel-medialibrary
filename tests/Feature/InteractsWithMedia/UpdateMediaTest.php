<?php

use Spatie\MediaLibrary\MediaCollections\Exceptions\MediaCannotBeUpdated;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

beforeEach(function () {
    $this->testModel->addMedia($this->getTestJpg())->usingName('test1')->preservingOriginal()->toMediaCollection();
    $this->testModel->addMedia($this->getTestJpg())->usingName('test2')->preservingOriginal()->toMediaCollection();
});

it('removes a media item if its not in the update array', function () {
    $mediaArray = $this->testModel->media->toArray();
    unset($mediaArray[0]);

    $this->testModel->updateMedia($mediaArray);
    $this->testModel->load('media');

    expect($this->testModel->media)->toHaveCount(1);
    expect($this->testModel->getFirstMedia()->name)->toEqual('test2');
});

it('removes a media item with eager loaded relation', function () {
    $mediaArray = $this->testModel->media->toArray();
    unset($mediaArray[0]);

    $this->testModel->load('media');
    $this->testModel->updateMedia($mediaArray);

    expect($this->testModel->media)->toHaveCount(1);
    expect($this->testModel->getFirstMedia()->name)->toEqual('test2');
});

it('renames media items', function () {
    $mediaArray = $this->testModel->media->toArray();

    $mediaArray[0]['name'] = 'testFoo';
    $mediaArray[1]['name'] = 'testBar';

    $this->testModel->updateMedia($mediaArray);
    $this->testModel->load('media');

    expect($this->testModel->media[0]->name)->toEqual('testFoo');
    expect($this->testModel->media[1]->name)->toEqual('testBar');
});

it('updates media item custom properties', function () {
    $mediaArray = $this->testModel->media->toArray();

    $mediaArray[0]['custom_properties']['foo'] = 'bar';

    $this->testModel->updateMedia($mediaArray);
    $this->testModel->load('media');

    expect($this->testModel->media[0]->getCustomProperty('foo'))->toEqual('bar');
});

it('reorders media items', function () {
    $mediaArray = $this->testModel->media->toArray();

    $differentOrder = array_reverse($mediaArray);

    $this->testModel->updateMedia($differentOrder);
    $this->testModel->load('media');

    $orderedMedia = $this->testModel->media->sortBy('order_column');

    expect($orderedMedia[1]->order_column)->toEqual($mediaArray[0]['order_column']);
    expect($orderedMedia[0]->order_column)->toEqual($mediaArray[1]['order_column']);
});

it('refuses to update media of another model', function () {
    $otherMedia = TestModel::create(['name' => 'other'])
        ->addMedia($this->getTestJpg())->usingName('other')->preservingOriginal()->toMediaCollection();

    $mediaArray = $this->testModel->media->toArray();
    $mediaArray[] = ['id' => $otherMedia->id, 'name' => 'changed'];

    expect(fn () => $this->testModel->updateMedia($mediaArray))->toThrow(MediaCannotBeUpdated::class);

    expect($otherMedia->fresh()->name)->toBe('other');
});

it('leaves the media untouched when the update array is refused', function () {
    $otherCollectionMedia = $this->testModel
        ->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('other-collection');

    $mediaArray = [
        ['id' => $this->testModel->media[0]->id, 'name' => 'changed'],
        ['id' => $otherCollectionMedia->id],
    ];

    expect(fn () => $this->testModel->updateMedia($mediaArray))->toThrow(MediaCannotBeUpdated::class);

    expect($this->testModel->fresh()->getMedia()->pluck('name')->all())->toBe(['test1', 'test2']);
});
