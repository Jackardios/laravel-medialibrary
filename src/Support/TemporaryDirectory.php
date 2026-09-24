<?php

namespace Spatie\MediaLibrary\Support;

use Illuminate\Support\Str;
use Spatie\TemporaryDirectory\TemporaryDirectory as BaseTemporaryDirectory;

class TemporaryDirectory
{
    public static function create(): BaseTemporaryDirectory
    {
        return new BaseTemporaryDirectory(static::getTemporaryDirectoryPath());
    }

    /**
     * An empty file in the temporary directory, for a copy that has to outlive the call that makes it.
     *
     * @internal
     */
    public static function createFile(): string
    {
        $path = static::getBasePath();

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return (string) tempnam($path, 'media-library');
    }

    protected static function getTemporaryDirectoryPath(): string
    {
        return static::getBasePath().DIRECTORY_SEPARATOR.Str::random(32);
    }

    protected static function getBasePath(): string
    {
        return config('media-library.temporary_directory_path') ?? storage_path('media-library/temp');
    }
}
