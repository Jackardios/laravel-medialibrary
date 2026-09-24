<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

use Exception;

class InvalidUrl extends Exception
{
    public static function doesNotStartWithProtocol(string $url): self
    {
        return new static("Could not add `{$url}` because it does not start with either `http://` or `https://`");
    }

    public static function resolvesToPrivateAddress(string $url, string $address): self
    {
        return new static("Could not add `{$url}` because its host resolves to `{$address}`, a private or reserved address. Add the host to `media_downloader_trusted_hosts` in the media-library config to allow it.");
    }
}
