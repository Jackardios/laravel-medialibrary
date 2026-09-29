<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaCannotBeUpdated extends Exception
{
    public static function doesNotBelongToCollection(string $collectionName, Media $media): self
    {
        return new static("Media id {$media->getKey()} is not part of collection `{$collectionName}`");
    }

    public static function doesNotBelongToModel(Media $media, Model $model): self
    {
        $modelClass = $model::class;

        return new static("Media id {$media->getKey()} does not belong to model {$modelClass} with id {$model->getKey()}");
    }

    public static function fileCannotBeMoved(Media $media, string $from, string $to): self
    {
        return new static("The file of media id {$media->getKey()} cannot be moved from `{$from}` to `{$to}`");
    }

    public static function cannotBeMovedToUnsavedModel(Media $media): self
    {
        return new static("Media id {$media->getKey()} cannot be moved to a model that is not saved yet. Save the model first.");
    }
}
