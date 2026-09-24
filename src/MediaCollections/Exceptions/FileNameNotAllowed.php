<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

class FileNameNotAllowed extends FileCannotBeAdded
{
    public static function create(string $originalName, string $sanitizedName, ?string $extension = null): self
    {
        $reason = $extension !== null
            ? "The extension `{$extension}` is not allowed because it poses a security risk."
            : 'Its extension is not allowed because it poses a security risk.';

        return new static("The file name `{$originalName}` was sanitized to `{$sanitizedName}`. {$reason}");
    }

    public static function configuresTheServer(string $originalName, string $sanitizedName): self
    {
        return new static("The file name `{$originalName}` was sanitized to `{$sanitizedName}`. It is not allowed because such a file configures PHP or the web server.");
    }

    public static function leavesItsDirectory(string $originalName, string $sanitizedName): self
    {
        return new static("The file name `{$originalName}` was sanitized to `{$sanitizedName}`. It is not allowed because it leaves its directory.");
    }
}
