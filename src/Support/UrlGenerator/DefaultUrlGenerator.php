<?php

namespace Spatie\MediaLibrary\Support\UrlGenerator;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DefaultUrlGenerator extends BaseUrlGenerator
{
    public function getUrl(): string
    {
        $url = $this->getDisk()->url($this->getUrlEncodedPathRelativeToRoot());

        return $this->versionUrl($url);
    }

    public function getTemporaryUrl(DateTimeInterface $expiration, array $options = []): string
    {
        return $this->getDisk()->temporaryUrl($this->getPathRelativeToRoot(), $expiration, $options);
    }

    public function getBaseMediaDirectoryUrl(): string
    {
        return $this->getDisk()->url('/');
    }

    public function getPath(): string
    {
        return $this->getRootOfDisk().$this->getPathRelativeToRoot();
    }

    public function getResponsiveImagesDirectoryUrl(): string
    {
        $path = $this->pathGenerator->getPathForResponsiveImages($this->media);

        // Responsive images, including those of the original, are stored on the conversions disk.
        $disk = $this->media->conversions_disk;

        return Str::finish(Storage::disk($disk)->url($this->urlEncodePathForDisk($path, $disk)), '/');
    }

    protected function getRootOfDisk(): string
    {
        return $this->getDisk()->path('/');
    }
}
