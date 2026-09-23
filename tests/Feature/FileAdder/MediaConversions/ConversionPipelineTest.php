<?php

use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

function modelWithConversions(Closure $register): TestModel
{
    $model = new class extends TestModel
    {
        public static Closure $register;

        public function registerMediaConversions(?Media $media = null): void
        {
            (self::$register)($this);
        }
    };

    $model::$register = $register;

    return $model::first();
}

it('performs every conversion after one without manipulations', function () {
    $media = modelWithConversions(function (TestModel $model) {
        $model->addMediaConversion('raw')->withoutManipulations()->nonQueued();
        $model->addMediaConversion('thumb')->width(20)->nonQueued();
    })->addMedia($this->getTestJpg())->toMediaCollection();

    expect($media->fresh()->generated_conversions)->toBe(['raw' => true, 'thumb' => true])
        ->and($media->getPath('raw'))->toBeFile()
        ->and($media->getPath('thumb'))->toBeFile();
});

it('regenerates responsive images after a conversion without manipulations', function () {
    $media = modelWithConversions(function (TestModel $model) {
        $model->addMediaConversion('raw')->withoutManipulations()->nonQueued();
    })->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection();

    app(FileManipulator::class)->regenerateDerivedFiles($media->fresh(), withResponsiveImages: true);

    $media = $media->fresh();

    expect($media->getPath('raw'))->toBeFile()
        ->and($media->hasResponsiveImages())->toBeTrue()
        ->and($media->responsive_images['media_library_original']['urls'])->not->toBeEmpty();
});
