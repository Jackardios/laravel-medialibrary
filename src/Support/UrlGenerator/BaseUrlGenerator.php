<?php

namespace Spatie\MediaLibrary\Support\UrlGenerator;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

abstract class BaseUrlGenerator implements UrlGenerator
{
    protected ?Media $media = null;

    protected ?Conversion $conversion = null;

    protected ?PathGenerator $pathGenerator = null;

    public function __construct(protected Config $config) {}

    /**
     * @return $this
     */
    public function setMedia(Media $media): UrlGenerator
    {
        $this->media = $media;

        return $this;
    }

    /**
     * @return $this
     */
    public function setConversion(Conversion $conversion): UrlGenerator
    {
        $this->conversion = $conversion;

        return $this;
    }

    /**
     * @return $this
     */
    public function setPathGenerator(PathGenerator $pathGenerator): UrlGenerator
    {
        $this->pathGenerator = $pathGenerator;

        return $this;
    }

    public function getPathRelativeToRoot(): string
    {
        if (is_null($this->conversion)) {
            return $this->pathGenerator->getPath($this->media).($this->media->file_name);
        }

        return $this->pathGenerator->getPathForConversions($this->media)
                .$this->conversion->getConversionFile($this->media);
    }

    protected function getDiskName(): string
    {
        return $this->conversion === null
            ? $this->media->disk
            : $this->media->conversions_disk;
    }

    protected function getDisk(): Filesystem
    {
        return Storage::disk($this->getDiskName());
    }

    protected function getUrlEncodedPathRelativeToRoot(): string
    {
        $diskConfig = config("filesystems.disks.{$this->getDiskName()}");

        // Laravel appends the path as is to local disks and to any disk with a configured `url`
        // (e.g. S3 behind a CDN). Without one, the S3 client builds and encodes the url itself.
        if (($diskConfig['driver'] ?? null) !== 'local' && empty($diskConfig['url'])) {
            return $this->getPathRelativeToRoot();
        }

        $segments = explode('/', $this->getPathRelativeToRoot());

        return implode('/', array_map(rawurlencode(...), $segments));
    }

    public function versionUrl(string $path = ''): string
    {
        // Media without an update time (e.g. of a model that is not saved yet) has no version.
        if (! $this->config->get('media-library.version_urls') || $this->media->updated_at === null) {
            return $path;
        }

        return "{$path}?v={$this->media->updated_at->timestamp}";
    }
}
