<?php

namespace Spatie\MediaLibrary\Support;

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

/**
 * The file name rules of the media library, for code that names or renames media itself:
 * validating a requested name, or making a name the way an added file gets one.
 */
class FileName
{
    /**
     * The name the default sanitizer gives an added file.
     *
     * @throws FileNameNotAllowed when the blocklist or allow-list does not accept the sanitized name
     */
    public static function sanitize(string $fileName): string
    {
        return app(FileAdder::class)->defaultSanitizer($fileName);
    }

    /**
     * Whether a media can be given this name as it is (`$media->file_name = ...`, `usingFileName()` with a
     * sanitizer that leaves it alone). `other/file.jpg` is allowed: it stores the file in a directory of its own.
     *
     * A name with spaces is allowed. A name with control or format characters (a line break, a zero width
     * joiner) is not, as the filesystem does not store it; `sanitize()` removes these.
     */
    public static function isAllowed(string $fileName): bool
    {
        try {
            static::guard($fileName);
        } catch (FileNameNotAllowed) {
            return false;
        }

        return true;
    }

    /**
     * @throws FileNameNotAllowed with the reason the name is refused
     */
    public static function guard(string $fileName): void
    {
        app(FileAdder::class)->guardAgainstUnsafeFileName($fileName, $fileName);
    }
}
