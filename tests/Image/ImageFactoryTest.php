<?php

use Spatie\MediaLibrary\Support\ImageFactory;

test('loading an image uses the correct driver', function () {
    config(['media-library.image_driver' => 'imagick']);

    $image = ImageFactory::load($this->getTestJpg());

    expect($image->driverName())->toBe('imagick');
})->skip(fn () => ! canTestImagick(), 'The imagick extension is not available.');
