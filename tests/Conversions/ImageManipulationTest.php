<?php

use Spatie\Image\Enums\AlignPosition;
use Spatie\Image\Enums\BorderType;
use Spatie\Image\Enums\Constraint;
use Spatie\Image\Enums\CropPosition;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Enums\Unit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\Conversions\Manipulations;

it('transforms parameters correctly', function () {
    // Mock the image object
    $image = Image::load(pathToImage: $this->getTestJpg());

    // Define manipulations
    $manipulations = [
        'border' => ['width' => 10, 'type' => 'expand'],
        'watermark' => ['fit' => 'contain', 'watermarkImage' => $this->getTestPng()],
        'resizeCanvas' => ['position' => 'center'],
        'resize' => ['constraints' => ['preserveAspectRatio'], 'width' => 100, 'height' => 100],
        'crop' => ['width' => 50, 'height' => 50, 'position' => 'topLeft'],
        'fit' => ['fit' => 'contain'],
        'flip' => ['flip' => 'horizontal'],
    ];

    $transformedParameters = [];

    // Create an instance of the class containing the logic
    $manipulator = new Manipulations($manipulations);

    foreach ($manipulations as $manipulationName => $parameters) {
        $parameters = $manipulator->transformParameters($manipulationName, $parameters);

        // Apply the manipulation
        $image->$manipulationName(...$parameters);

        // Store the transformed parameters for assertions
        $transformedParameters[$manipulationName] = $parameters;
    }

    // Assertions to check if parameters have been correctly transformed
    expect($transformedParameters['border']['type'])->toBeInstanceOf(BorderType::class)
        ->and($transformedParameters['watermark']['fit'])->toBeInstanceOf(Fit::class)
        ->and($transformedParameters['resizeCanvas']['position'])->toBeInstanceOf(AlignPosition::class)
        ->and($transformedParameters['resize']['constraints'][0])->toBeInstanceOf(Constraint::class)
        ->and($transformedParameters['crop']['position'])->toBeInstanceOf(CropPosition::class)
        ->and($transformedParameters['fit']['fit'])->toBeInstanceOf(Fit::class)
        ->and($transformedParameters['flip']['flip'])->toBeInstanceOf(FlipDirection::class);
});

it('handles parameters that are already enum instances', function () {
    // Mock the image object
    $image = Image::load(pathToImage: $this->getTestJpg());

    // Define manipulations with parameters already as enum instances
    $manipulations = [
        'border' => ['width' => 10, 'type' => BorderType::Expand],
        'watermark' => ['fit' => Fit::Contain, 'watermarkImage' => $this->getTestPng()],
        'resizeCanvas' => ['position' => AlignPosition::Center],
        'resize' => ['constraints' => [Constraint::PreserveAspectRatio], 'width' => 100, 'height' => 100],
        'crop' => ['width' => 50, 'height' => 50, 'position' => CropPosition::TopLeft],
        'fit' => ['fit' => Fit::Contain],
        'flip' => ['flip' => FlipDirection::Horizontal],
    ];

    $transformedParameters = [];

    // Create an instance of the class containing the logic
    $manipulator = new Manipulations($manipulations);

    foreach ($manipulations as $manipulationName => $parameters) {
        $parameters = $manipulator->transformParameters($manipulationName, $parameters);

        // Apply the manipulation
        $image->$manipulationName(...$parameters);

        // Store the transformed parameters for assertions
        $transformedParameters[$manipulationName] = $parameters;
    }

    // Assertions to check if parameters remain unchanged
    expect($transformedParameters['border']['type'])->toBeInstanceOf(BorderType::class)
        ->and($transformedParameters['watermark']['fit'])->toBeInstanceOf(Fit::class)
        ->and($transformedParameters['resizeCanvas']['position'])->toBeInstanceOf(AlignPosition::class)
        ->and($transformedParameters['resize']['constraints'][0])->toBeInstanceOf(Constraint::class)
        ->and($transformedParameters['crop']['position'])->toBeInstanceOf(CropPosition::class)
        ->and($transformedParameters['fit']['fit'])->toBeInstanceOf(Fit::class)
        ->and($transformedParameters['flip']['flip'])->toBeInstanceOf(FlipDirection::class);
});

it('casts the watermark units stored as strings', function () {
    $parameters = (new Manipulations)->transformParameters('watermark', [
        'watermarkImage' => $this->getTestPng(),
        'paddingUnit' => 'percent',
        'widthUnit' => 'percent',
        'heightUnit' => 'pixel',
    ]);

    expect($parameters['paddingUnit'])->toBe(Unit::Percent)
        ->and($parameters['widthUnit'])->toBe(Unit::Percent)
        ->and($parameters['heightUnit'])->toBe(Unit::Pixel);

    Image::load($this->getTestJpg())->watermark(...$parameters);
});

it('casts enum parameters passed by position', function (string $manipulationName, array $parameters, int $position, UnitEnum $expected) {
    // Positional arguments are what `withManipulations(['thumb' => ['fit' => [Fit::Contain, 30, 30]]])`
    // stores; after the JSON round trip through the manipulations column they are plain values.
    $transformed = (new Manipulations)->transformParameters($manipulationName, $parameters);

    expect($transformed[$position])->toBe($expected);

    Image::load($this->getTestJpg())->$manipulationName(...$transformed);
})->with([
    'fit' => ['fit', ['contain', 30, 30], 0, Fit::Contain],
    'crop' => ['crop', [20, 20, 'topLeft'], 2, CropPosition::TopLeft],
    'border' => ['border', [5, 'expand'], 1, BorderType::Expand],
    'flip' => ['flip', ['horizontal'], 0, FlipDirection::Horizontal],
    'orientation' => ['orientation', [90], 0, Orientation::Rotate90],
    'resizeCanvas' => ['resizeCanvas', [400, 400, 'center'], 2, AlignPosition::Center],
]);

it('casts constraints passed by position', function () {
    $transformed = (new Manipulations)->transformParameters('resize', [30, 30, ['preserveAspectRatio']]);

    expect($transformed[2])->toBe([Constraint::PreserveAspectRatio]);

    Image::load($this->getTestJpg())->resize(...$transformed);
});

it('casts a numeric string for an int-backed enum', function () {
    $transformed = (new Manipulations)->transformParameters('orientation', ['orientation' => '90']);

    expect($transformed['orientation'])->toBe(Orientation::Rotate90);
});

it('leaves parameters of unknown manipulations untouched', function () {
    expect((new Manipulations)->transformParameters('doesNotExist', ['contain']))->toBe(['contain']);
});

it('gets the first argument of a manipulation stored with or without an array', function (mixed $argument) {
    expect((new Manipulations(['quality' => $argument]))->getFirstManipulationArgument('quality'))->toBe('40');
})->with([
    'list' => [['40']],
    'named' => [['quality' => '40']],
    'single value' => ['40'],
]);
