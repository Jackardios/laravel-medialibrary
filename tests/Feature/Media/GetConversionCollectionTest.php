<?php

use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithConversion;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithCountedConversions;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithResponsiveImages;

beforeEach(function () {
    if (PHP_VERSION_ID < 80300) {
        $this->markTestSkipped('The conversion collection is only memoized on PHP 8.3 and later.');
    }
});

function conversionNames(ConversionCollection $conversions): array
{
    return $conversions->map(fn ($conversion) => $conversion->getName())->values()->all();
}

it('returns the same collection for repeated calls on one media', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $conversions = $media->getConversionCollection();

    expect($media->getConversionCollection())->toBe($conversions)
        ->and(conversionNames($conversions))->toBe(conversionNames(ConversionCollection::createForMedia($media)))
        ->and($conversions->dependsOnModelInstance())->toBeFalse();
});

it('builds a fresh collection after the manipulations change', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $before = $media->getConversionCollection();

    $media->manipulations = ['thumb' => ['greyscale' => []]];

    $after = $media->getConversionCollection();

    expect($after)->not->toBe($before)
        ->and($after->getByName('thumb')->getManipulations()->getManipulationArgument('greyscale'))->toBe([])
        ->and($before->getByName('thumb')->getManipulations()->getManipulationArgument('greyscale'))->toBeNull();
});

it('builds a fresh collection after the collection name changes', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $before = $media->getConversionCollection();

    $media->collection_name = 'other';

    expect($media->getConversionCollection())->not->toBe($before);
});

it('builds a fresh collection after the model type changes', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $before = $media->getConversionCollection();

    $media->model_type = TestModelWithResponsiveImages::class;

    $after = $media->getConversionCollection();

    expect($after)->not->toBe($before)
        ->and(conversionNames($before))->toBe(['thumb', 'keep_original_format'])
        ->and(conversionNames($after))->toContain('pngtojpg');
});

it('gives a clone its own collection', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $conversions = $media->getConversionCollection();
    $clone = clone $media;

    expect($clone->getConversionCollection())->not->toBe($conversions)
        ->and($media->getConversionCollection())->toBe($conversions);
});

it('keeps the collection out of serialization', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    $conversions = $media->getConversionCollection();
    $serialized = serialize($media);

    /** @var Media $restored */
    $restored = unserialize($serialized);

    expect($serialized)->not->toContain(ConversionCollection::class)
        ->and($restored->getConversionCollection())->not->toBe($conversions);
});

it('does not memoize conversions registered using the model instance', function () {
    $media = $this->testModelWithConversionUsingModelInstance->addMedia($this->getTestJpg())->toMediaCollection();

    $first = $media->getConversionCollection();

    expect($first->dependsOnModelInstance())->toBeTrue()
        ->and($media->getConversionCollection())->not->toBe($first)
        ->and(conversionNames($first))->toBe(['lazy-conversion', 'eager-conversion']);
});

it('registers the conversions once while building urls and a srcset', function () {
    $model = TestModelWithCountedConversions::first();

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection();
    $media = Media::findOrFail($media->id);

    TestModelWithCountedConversions::$registrations = 0;

    for ($i = 0; $i < 5; $i++) {
        $media->getUrl('thumb');
    }

    $srcset = $media->getSrcset('thumb');

    expect($srcset)->not->toBe('')
        ->and(TestModelWithCountedConversions::$registrations)->toBe(1);
});
