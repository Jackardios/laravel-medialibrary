<?php

it('can have a conversion', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    expect($media->hasGeneratedConversion('thumb'))->toBeTrue();
});

it('stores a conversion marked as generated', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection();

    $media->markAsConversionGenerated('thumb');

    expect($media->fresh()->hasGeneratedConversion('thumb'))->toBeTrue();
});
