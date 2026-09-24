<?php

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Exceptions\DiskCannotBeAccessed;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $this->testModel->load('media');
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

it('includes media added after the media relation was loaded', function () {
    Model::preventLazyLoading();

    $media = $this->testModel->addMedia($this->getTestPng())->preservingOriginal()->toMediaCollection();

    expect($this->testModel->getMedia()->pluck('id')->all())->toBe([1, $media->id]);
});

it('deletes media added after the media relation was loaded', function () {
    $media = $this->testModel->addMedia($this->getTestPng())->preservingOriginal()->toMediaCollection();

    $this->testModel->deleteMedia($media);

    expect(Media::find($media->id))->toBeNull();
});

it('clears media added after the media relation was loaded', function () {
    $this->testModel->addMedia($this->getTestPng())->preservingOriginal()->toMediaCollection();

    $this->testModel->clearMediaCollection();

    expect(Media::count())->toBe(0);
});

it('leaves out media that could not be added from the loaded media relation', function () {
    config()->set('filesystems.disks.invalid_disk', [
        'driver' => 's3',
        'secret' => 'test',
        'key' => 'test',
        'region' => 'test',
        'bucket' => 'test',
    ]);

    expect(fn () => $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('default', 'invalid_disk'))
        ->toThrow(DiskCannotBeAccessed::class);

    expect($this->testModel->getMedia()->pluck('id')->all())->toBe([1]);
});
