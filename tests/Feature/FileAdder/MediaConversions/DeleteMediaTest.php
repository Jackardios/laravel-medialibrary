<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Events\CollectionHasBeenClearedEvent;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithoutMediaConversions;

beforeEach(function () {
    foreach (range(1, 3) as $index) {
        $this->testModelWithoutMediaConversions
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->toMediaCollection();

        $this->testModelWithoutMediaConversions
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->toMediaCollection('images');
    }
});

it('can clear a collection', function () {
    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(3);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(3);

    $this->testModelWithoutMediaConversions->clearMediaCollection('images');
    $this->testModelWithoutMediaConversions = $this->testModelWithoutMediaConversions->fresh();

    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(3);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(0);
});

it('can clear the default collection', function () {
    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(3);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(3);

    $this->testModelWithoutMediaConversions->clearMediaCollection();
    $this->testModelWithoutMediaConversions = $this->testModelWithoutMediaConversions->fresh();

    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(0);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(3);
});

it('can clear a collection excluding a single media', function () {
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(3);

    $excludedMedia = $this->testModelWithoutMediaConversions->getFirstMedia('images');

    $this->testModelWithoutMediaConversions->clearMediaCollectionExcept('images', $excludedMedia);

    expect($excludedMedia)->toEqual($this->testModelWithoutMediaConversions->getMedia('images')[0]);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(1);
});

it('can clear a collection excluding some media', function () {
    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(3);
    expect($this->testModelWithoutMediaConversions->getMedia('images'))->toHaveCount(3);

    $excludedMedia = $this->testModelWithoutMediaConversions->getMedia('images')->take(2);

    $this->testModelWithoutMediaConversions->clearMediaCollectionExcept('images', $excludedMedia);
    $this->testModelWithoutMediaConversions = $this->testModelWithoutMediaConversions->fresh();

    expect($this->testModelWithoutMediaConversions->getMedia('default'))->toHaveCount(3);
    expect($excludedMedia[0])->toEqual($this->testModelWithoutMediaConversions->getMedia('images')[0]);
    expect($excludedMedia[1])->toEqual($this->testModelWithoutMediaConversions->getMedia('images')[1]);
});

it('announces a collection cleared except some media only once it is empty', function () {
    Event::fake([CollectionHasBeenClearedEvent::class]);

    $model = $this->testModelWithoutMediaConversions;

    $model->clearMediaCollectionExcept('images', $model->getFirstMedia('images'));

    Event::assertNotDispatched(CollectionHasBeenClearedEvent::class);
    expect($model->fresh()->getMedia('images'))->toHaveCount(1);

    $model->clearMediaCollectionExcept('images', $model->getFirstMedia('default'));

    Event::assertDispatched(CollectionHasBeenClearedEvent::class, fn (CollectionHasBeenClearedEvent $event) => $event->collectionName === 'images');
    expect($model->fresh()->getMedia('images'))->toHaveCount(0);
});

it('provides a chainable method for clearing a collection', function () {
    $result = $this->testModelWithoutMediaConversions->clearMediaCollection('images');

    expect($result)->toBeInstanceOf(TestModelWithoutMediaConversions::class);
});

it('will remove the files when clearing a collection', function () {
    $ids = $this->testModelWithoutMediaConversions->getMedia('images')->pluck('id');

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeTrue();
    });

    $this->testModelWithoutMediaConversions->clearMediaCollection('images');

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeFalse();
    });
});

it('will remove the files when deleting a subject without media conversions', function () {
    $ids = $this->testModelWithoutMediaConversions->getMedia('images')->pluck('id');

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeTrue();
    });

    $this->testModelWithoutMediaConversions->delete();

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeFalse();
    });
});

it('will not remove the files when deleting a subject and preserving media', function () {
    $ids = $this->testModelWithoutMediaConversions->getMedia('images')->pluck('id');

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeTrue();
    });

    $this->testModelWithoutMediaConversions->deletePreservingMedia();

    $ids->map(function ($id) {
        expect(File::isDirectory($this->getMediaDirectory($id)))->toBeTrue();
    });
});
