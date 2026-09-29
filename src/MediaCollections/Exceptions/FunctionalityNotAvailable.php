<?php

namespace Spatie\MediaLibrary\MediaCollections\Exceptions;

use Exception;

class FunctionalityNotAvailable extends Exception
{
    public static function curlRequiredToConnectToCheckedAddress(string $url): self
    {
        return new static("Could not download `{$url}`: the HttpFacadeDownloader needs the curl extension to connect to the address it checked. Install ext-curl, use the DefaultDownloader, or list the host in `media_downloader_trusted_hosts`.");
    }
}
