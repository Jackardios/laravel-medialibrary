<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

class FileNameNotAllowed extends FileCannotBeAdded
{
    public static function create(string $originalName, string $sanitizedName, ?string $extension = null): self
    {
        return static::because($originalName, $sanitizedName, $extension !== null
            ? "the extension `{$extension}` poses a security risk"
            : 'its extension poses a security risk');
    }

    public static function extensionIsNotAllowed(string $originalName, string $sanitizedName, ?string $extension = null): self
    {
        return static::because($originalName, $sanitizedName, $extension !== null
            ? "the extension `{$extension}` is not one of the allowed extensions"
            : 'it has no extension, and only the allowed extensions are accepted');
    }

    public static function configuresTheServer(string $originalName, string $sanitizedName): self
    {
        return static::because($originalName, $sanitizedName, 'such a file configures PHP or the web server');
    }

    public static function leavesItsDirectory(string $originalName, string $sanitizedName): self
    {
        return static::because($originalName, $sanitizedName, 'it leaves its directory');
    }

    public static function containsCharacter(string $originalName, string $sanitizedName, string $character): self
    {
        return static::because($originalName, $sanitizedName, $character === ':'
            ? 'it contains `:`, which names a drive or a data stream on Windows'
            : "it contains `{$character}`, which separates directories on Windows");
    }

    public static function containsInvisibleCharacter(string $originalName, string $sanitizedName): self
    {
        // The names are shown without these characters: they would not be seen, or would break the message.
        $visible = fn (string $name) => (string) preg_replace('#\p{C}+#u', '', mb_scrub($name, 'UTF-8'));

        return static::because(
            $visible($originalName),
            $visible($sanitizedName),
            'it has a control or format character (such as a line break or a zero width joiner) or is not valid utf-8, which the filesystem does not store',
        );
    }

    /**
     * A name that was checked as it is (a rename, `FileName::guard()`) was not sanitized, so the message does not say it was.
     */
    protected static function because(string $originalName, string $fileName, string $reason): self
    {
        $subject = $originalName === $fileName
            ? "The file name `{$fileName}` is not allowed"
            : "The file name `{$originalName}` was sanitized to `{$fileName}`, which is not allowed";

        return new static("{$subject}: {$reason}.");
    }
}
