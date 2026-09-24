<?php

use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

it('adds each media of an unsaved model with its own options', function () {
    $model = new TestModel(['name' => 'unsaved']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();
    $model->addMedia($this->getTestPng())->preservingOriginal()->withResponsiveImages()->toMediaCollection();
    $model->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('other.jpg')->toMediaCollection();

    $model->save();

    $media = $model->fresh()->getMedia()->keyBy('file_name');

    expect($media)->toHaveCount(3)
        ->and($media['test.jpg']->responsive_images)->toBe([])
        ->and($media['test.png']->responsive_images)->toHaveKey('media_library_original')
        ->and($media['other.jpg']->responsive_images)->toBe([]);
});

it('does not register a listener for every media added to an unsaved model', function () {
    $listeners = fn () => count(app('events')->getListeners('eloquent.created: '.TestModel::class));

    $before = $listeners();

    foreach (range(1, 3) as $index) {
        $model = new TestModel(['name' => "unsaved {$index}"]);
        $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();
        $model->save();

        expect($model->getMedia())->toHaveCount(1);
    }

    expect($listeners())->toBe($before);
});
