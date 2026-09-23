<?php

use Illuminate\Support\Facades\Event;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
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

it('lets completed-conversion listeners see the conversion as generated in the database', function () {
    $seen = [];

    Event::listen(ConversionHasBeenCompletedEvent::class, function (ConversionHasBeenCompletedEvent $event) use (&$seen) {
        $seen[$event->conversion->getName()] = Media::find($event->media->id)->hasGeneratedConversion($event->conversion->getName());
    });

    modelWithConversions(function (TestModel $model) {
        $model->addMediaConversion('thumb')->width(20)->nonQueued();
        $model->addMediaConversion('small')->width(30)->nonQueued();
    })->addMedia($this->getTestJpg())->toMediaCollection();

    expect($seen)->toBe(['thumb' => true, 'small' => true]);
});

it('keeps the generated conversions when a completed-conversion listener refreshes the media', function () {
    Event::listen(ConversionHasBeenCompletedEvent::class, fn (ConversionHasBeenCompletedEvent $event) => $event->media->refresh());

    $media = modelWithConversions(function (TestModel $model) {
        $model->addMediaConversion('thumb')->width(20)->nonQueued();
        $model->addMediaConversion('small')->width(30)->nonQueued();
    })->addMedia($this->getTestJpg())->toMediaCollection();

    expect(Media::find($media->id)->generated_conversions)->toBe(['thumb' => true, 'small' => true]);
});

it('records and announces the conversions that completed before a later one failed', function () {
    $completed = [];

    Event::listen(ConversionHasBeenCompletedEvent::class, function (ConversionHasBeenCompletedEvent $event) use (&$completed) {
        $completed[] = $event->conversion->getName();
    });

    Event::listen(ConversionWillStartEvent::class, function (ConversionWillStartEvent $event) {
        if ($event->conversion->getName() === 'broken') {
            throw new RuntimeException('conversion failed');
        }
    });

    $model = modelWithConversions(function (TestModel $model) {
        $model->addMediaConversion('thumb')->width(20)->nonQueued();
        $model->addMediaConversion('broken')->width(30)->nonQueued();
    });

    expect(fn () => $model->addMedia($this->getTestJpg())->toMediaCollection())
        ->toThrow(RuntimeException::class, 'conversion failed');

    expect($completed)->toBe(['thumb'])
        ->and(Media::first()->generated_conversions)->toBe(['thumb' => true]);
});

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
