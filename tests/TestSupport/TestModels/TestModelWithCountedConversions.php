<?php

namespace Spatie\MediaLibrary\Tests\TestSupport\TestModels;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TestModelWithCountedConversions extends TestModelWithResponsiveImages
{
    public static int $registrations = 0;

    public function registerMediaConversions(?Media $media = null): void
    {
        static::$registrations++;

        parent::registerMediaConversions($media);
    }
}
