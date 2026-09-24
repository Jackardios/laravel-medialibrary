<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

use Exception;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaCannotBeUpdated extends Exception
{
    public static function doesNotBelongToCollection(string $collectionName, Media $media): self
    {
        return new static("Media id {$media->getKey()} is not part of collection `{$collectionName}`");
    }

    public static function fileCannotBeMoved(Media $media, string $from, string $to): self
    {
        return new static("The file of media id {$media->getKey()} cannot be moved from `{$from}` to `{$to}`");
    }
}
