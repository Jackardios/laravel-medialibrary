<?php

use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

class CountingOptimizer extends Jpegoptim
{
    public static int $created = 0;

    public function __construct($options = [])
    {
        static::$created++;

        parent::__construct($options);
    }
}

class TestModelWithOptimizedThumb extends TestModel
{
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(20)->nonQueued();
    }
}

beforeEach(function () {
    CountingOptimizer::$created = 0;

    // The factory only takes the config when it names one of the image package's optimizers.
    config()->set('media-library.image_optimizers', [Jpegoptim::class => [], CountingOptimizer::class => []]);
});

it('builds no optimizers for a conversion url', function () {
    $media = TestModelWithOptimizedThumb::first()->addMedia($this->getTestJpg())->toMediaCollection();
    CountingOptimizer::$created = 0;

    expect($media->fresh()->getUrl('thumb'))->toEndWith('/test-thumb.jpg')
        ->and(CountingOptimizer::$created)->toBe(0);
});

it('optimizes a conversion with the optimizers from the config', function () {
    $manipulations = Conversion::create('thumb')->getManipulations()->toArray();

    expect(array_keys($manipulations))->toBe(['optimize', 'format'])
        ->and($manipulations['optimize'][0]->getOptimizers())->toHaveCount(2)
        ->and($manipulations['optimize'][0]->getOptimizers()[1])->toBeInstanceOf(CountingOptimizer::class);
});

it('optimizes with the default chain without building it when no optimizers are given', function () {
    $conversion = Conversion::create('thumb')->optimize();

    expect($conversion->getManipulations()->getManipulationArgument('optimize'))->toBe([])
        ->and(CountingOptimizer::$created)->toBe(0);
});

it('does not optimize a conversion that is not optimized', function () {
    $conversion = Conversion::create('thumb')->nonOptimized();

    expect($conversion->getManipulations()->toArray())->toBe(['format' => ['jpg']])
        ->and(CountingOptimizer::$created)->toBe(0);
});
