<?php

namespace Spatie\MediaLibrary\ResponsiveImages;

use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RegisteredResponsiveImages
{
    public Collection $files;

    public string $generatedFor;

    /** Built once: every file of the set lies in the same directory. */
    protected ?string $directoryUrl = null;

    public function __construct(protected Media $media, string $conversionName = '')
    {
        $this->generatedFor = $conversionName === ''
            ? 'media_library_original'
            : $conversionName;

        $this->files = collect($media->responsive_images[$this->generatedFor]['urls'] ?? [])
            ->map(fn (string $fileName) => new ResponsiveImage($fileName, $media))
            ->filter(fn (ResponsiveImage $responsiveImage) => $responsiveImage->generatedFor() === $this->generatedFor);
    }

    public function getUrls(): array
    {
        return $this->files
            ->map(fn (ResponsiveImage $responsiveImage) => $this->urlOf($responsiveImage))
            ->values()
            ->toArray();
    }

    public function getFilenames(): array
    {
        return $this->files->pluck('fileName')->toArray();
    }

    public function getSrcset(): string
    {
        $filesSrcset = $this->files
            ->map(fn (ResponsiveImage $responsiveImage) => "{$this->urlOf($responsiveImage)} {$responsiveImage->width()}w")
            ->implode(', ');

        $shouldAddPlaceholderSvg = config('media-library.responsive_images.use_tiny_placeholders')
            && $this->getPlaceholderSvg();

        if ($shouldAddPlaceholderSvg) {
            $filesSrcset .= ', '.$this->getPlaceholderSvg().' 32w';
        }

        return $filesSrcset;
    }

    protected function urlOf(ResponsiveImage $responsiveImage): string
    {
        $this->directoryUrl ??= ResponsiveImage::directoryUrl($this->media, $this->generatedFor);

        return $responsiveImage->urlIn($this->directoryUrl);
    }

    public function getPlaceholderSvg(): ?string
    {
        return $this->media->responsive_images[$this->generatedFor]['base64svg'] ?? null;
    }

    public function delete(): void
    {
        $this->files->each->delete();

        $responsiveImages = $this->media->responsive_images;

        unset($responsiveImages[$this->generatedFor]);

        $this->media->responsive_images = $responsiveImages;

        $this->media->save();
    }
}
