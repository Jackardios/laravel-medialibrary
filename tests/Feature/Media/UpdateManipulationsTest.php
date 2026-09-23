<?php

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;

it('will create derived files when manipulations have changed', function () {
    $testModelClass = new class extends TestModel
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this->addMediaConversion('update_test');
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    /** @var Media $media */
    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    touch($media->getPath('update_test'), time() - 1);

    $conversionModificationTime = filemtime($media->getPath('update_test'));

    $media->manipulations = [
        'update_test' => [
            'width' => [1],
            'height' => [1],
        ],
    ];

    $media->save();

    $modificationTimeAfterManipulationChanged = filemtime($media->getPath('update_test'));

    expect($modificationTimeAfterManipulationChanged)->toBeGreaterThan($conversionModificationTime);
});

it('will not create derived files when manipulations have not changed', function () {
    $testModelClass = new class extends TestModel
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this->addMediaConversion('update_test');
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    /** @var Media $media */
    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    $media->manipulations = [
        'update_test' => [
            'width' => [1],
            'height' => [1],
        ],
    ];

    $media->save();

    touch($media->getPath('update_test'), time() - 1);

    $conversionModificationTime = filemtime($media->getPath('update_test'));

    $media->manipulations = [
        'update_test' => [
            'width' => [1],
            'height' => [1],
        ],
    ];

    $media->updated_at = now()->addSecond();

    $media->save();

    $modificationTimeAfterManipulationChanged = filemtime($media->getPath('update_test'));

    expect($modificationTimeAfterManipulationChanged)->toEqual($conversionModificationTime);
});

it('performs manipulations stored in the database with enum arguments by position', function () {
    $testModelClass = new class extends TestModel
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this->addMediaConversion('update_test')->nonQueued();
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel
        ->addMedia($this->getTestJpg())
        ->withManipulations(['update_test' => ['fit' => [Fit::Contain, 30, 30]]])
        ->toMediaCollection('images');

    expect($media->fresh()->manipulations)->toBe(['update_test' => ['fit' => ['contain', 30, 30]]]);

    [$width, $height] = getimagesize($media->getPath('update_test'));

    expect(max($width, $height))->toBe(30);
});

it('performs resize constraints stored in the database', function () {
    $testModelClass = new class extends TestModel
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this->addMediaConversion('update_test')->nonQueued();
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    $media->manipulations = [
        'update_test' => ['width' => ['width' => 40, 'constraints' => ['preserveAspectRatio']]],
    ];
    $media->save();

    [$width] = getimagesize($media->getPath('update_test'));

    expect($width)->toBe(40);
});

it('performs the manipulation from the media-specific manipulations docs', function () {
    $testModelClass = new class extends TestModel
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this->addMediaConversion('update_test')->nonQueued();
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    [$originalWidth, $originalHeight] = getimagesize($media->getPath());

    // docs/advanced-usage/storing-media-specific-manipulations.md
    $media->manipulations = [
        'update_test' => ['orientation' => '90'],
    ];
    $media->save();

    [$width, $height] = getimagesize($media->getPath('update_test'));

    expect([$width, $height])->toBe([$originalHeight, $originalWidth]);
});
