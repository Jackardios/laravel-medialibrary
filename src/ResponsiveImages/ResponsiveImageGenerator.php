<?php

namespace Spatie\MediaLibrary\ResponsiveImages;

use Closure;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\Events\ResponsiveImagesGeneratedEvent;
use Spatie\MediaLibrary\ResponsiveImages\Exceptions\InvalidTinyJpg;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\TinyPlaceholderGenerator;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\WidthCalculator;
use Spatie\MediaLibrary\Support\File;
use Spatie\MediaLibrary\Support\FileNamer\FileNamer;
use Spatie\MediaLibrary\Support\ImageFactory;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Spatie\TemporaryDirectory\TemporaryDirectory as BaseTemporaryDirectory;
use Throwable;

class ResponsiveImageGenerator
{
    protected const DEFAULT_CONVERSION_QUALITY = 90;

    protected FileNamer $fileNamer;

    public function __construct(
        protected Filesystem $filesystem,
        protected WidthCalculator $widthCalculator,
        protected TinyPlaceholderGenerator $tinyPlaceholderGenerator
    ) {
        $this->fileNamer = app(config('media-library.file_namer'));
    }

    public function generateResponsiveImages(Media $media, ?string $baseImage = null): void
    {
        $temporaryDirectory = TemporaryDirectory::create();

        try {
            // Callers that already have a local copy of the original (e.g. the regenerate pipeline,
            // which downloads it once for the conversions) can pass it in to avoid a second download.
            $baseImage ??= app(Filesystem::class)->copyFromMediaLibrary(
                $media,
                $temporaryDirectory->path(Str::random(16).'.'.$media->extension)
            );

            $this->replaceResponsiveImages($media, 'media_library_original', function () use ($media, $baseImage, $temporaryDirectory) {
                foreach ($this->widthCalculator->calculateWidthsFromFile($baseImage) as $width) {
                    $this->generateResponsiveImage($media, $baseImage, 'media_library_original', $width, $temporaryDirectory);
                }

                $this->generateTinyJpg($media, $baseImage, 'media_library_original', $temporaryDirectory);
            });
        } finally {
            $temporaryDirectory->delete();
        }

        event(new ResponsiveImagesGeneratedEvent($media));
    }

    public function generateResponsiveImagesForConversion(Media $media, Conversion $conversion, string $baseImage): void
    {
        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $widthCalculator = $conversion->getWidthCalculator() ?? $this->widthCalculator;

            $this->replaceResponsiveImages($media, $conversion->getName(), function () use ($media, $conversion, $baseImage, $widthCalculator, $temporaryDirectory) {
                foreach ($widthCalculator->calculateWidthsFromFile($baseImage) as $width) {
                    $this->generateResponsiveImage($media, $baseImage, $conversion->getName(), $width, $temporaryDirectory, $this->getConversionQuality($conversion));
                }

                $this->generateTinyJpg($media, $baseImage, $conversion->getName(), $temporaryDirectory);
            });
        } finally {
            $temporaryDirectory->delete();
        }
    }

    /**
     * Generate a new set of responsive images for a conversion and only then remove the files of
     * the previous set it no longer uses. When generating fails, the previous set stays in place.
     *
     * @param  Closure(): void  $generate
     */
    protected function replaceResponsiveImages(Media $media, string $conversionName, Closure $generate): void
    {
        $previous = $media->responsive_images[$conversionName] ?? null;
        $previousFileNames = $previous['urls'] ?? [];

        $this->setResponsiveImagesFor($media, $conversionName, [...$previous ?? [], 'urls' => []]);

        try {
            $generate();
        } catch (Throwable $exception) {
            $newFileNames = $media->responsive_images[$conversionName]['urls'] ?? [];

            // Files with the name of a previous one overwrote it with an identical image.
            $this->removeResponsiveImageFiles($media, array_diff($newFileNames, $previousFileNames));

            $this->setResponsiveImagesFor($media, $conversionName, $previous);
            $media->save();

            throw $exception;
        }

        $this->removeResponsiveImageFiles(
            $media,
            array_diff($previousFileNames, $media->responsive_images[$conversionName]['urls'] ?? [])
        );
    }

    protected function setResponsiveImagesFor(Media $media, string $conversionName, ?array $properties): void
    {
        $responsiveImages = $media->responsive_images;

        if ($properties === null) {
            unset($responsiveImages[$conversionName]);
        } else {
            $responsiveImages[$conversionName] = $properties;
        }

        $media->responsive_images = $responsiveImages;
    }

    /** @param  array<int, string>  $fileNames */
    protected function removeResponsiveImageFiles(Media $media, array $fileNames): void
    {
        $directory = $this->filesystem->getResponsiveImagesDirectory($media);

        foreach ($fileNames as $fileName) {
            $this->filesystem->removeFile($media, $directory.$fileName, $media->conversions_disk);
        }
    }

    private function getConversionQuality(Conversion $conversion): int
    {
        return $conversion->getManipulations()->getFirstManipulationArgument('quality') ?: self::DEFAULT_CONVERSION_QUALITY;
    }

    public function generateResponsiveImage(
        Media $media,
        string $baseImage,
        string $conversionName,
        int $targetWidth,
        BaseTemporaryDirectory $temporaryDirectory,
        int $conversionQuality = self::DEFAULT_CONVERSION_QUALITY
    ): void {
        $extension = $this->fileNamer->extensionFromBaseImage($baseImage);
        $responsiveImagePath = $this->fileNamer->temporaryFileName($media, $extension);

        $tempDestination = $temporaryDirectory->path($responsiveImagePath);

        ImageFactory::load($baseImage)
            ->optimize()
            ->width($targetWidth)
            ->quality($conversionQuality)
            ->save($tempDestination);

        $responsiveImageHeight = ImageFactory::load($tempDestination)->getHeight();

        // Users can customize the name like they want, but we expect the last part in a certain format
        $fileName = $this->addPropertiesToFileName(
            $responsiveImagePath,
            $conversionName,
            $targetWidth,
            $responsiveImageHeight,
            $extension
        );

        $responsiveImagePath = $temporaryDirectory->path($fileName);

        rename($tempDestination, $responsiveImagePath);

        $this->filesystem->copyToMediaLibrary($responsiveImagePath, $media, 'responsiveImages');

        ResponsiveImage::register($media, $fileName, $conversionName);
    }

    public function generateTinyJpg(
        Media $media,
        string $originalImagePath,
        string $conversionName,
        BaseTemporaryDirectory $temporaryDirectory
    ): void {
        if (! config('media-library.responsive_images.use_tiny_placeholders')) {
            return;
        }

        $tempDestination = $temporaryDirectory->path('tiny.jpg');

        $this->tinyPlaceholderGenerator->generateTinyPlaceholder($originalImagePath, $tempDestination);

        $this->guardAgainstInvalidTinyPlaceHolder($tempDestination);

        $tinyImageDataBase64 = base64_encode(file_get_contents($tempDestination));

        $tinyImageBase64 = 'data:image/jpeg;base64,'.$tinyImageDataBase64;

        $originalImage = ImageFactory::load($originalImagePath);

        $originalImageWidth = $originalImage->getWidth();

        $originalImageHeight = $originalImage->getHeight();

        $svg = view('media-library::placeholderSvg', compact( // @phpstan-ignore argument.type
            'originalImageWidth',
            'originalImageHeight',
            'tinyImageBase64'
        ));

        $base64Svg = 'data:image/svg+xml;base64,'.base64_encode($svg);

        ResponsiveImage::registerTinySvg($media, $base64Svg, $conversionName);
    }

    protected function appendToFileName(string $filePath, string $suffix, ?string $extensionFilePath = null): string
    {
        $baseName = pathinfo($filePath, PATHINFO_FILENAME);

        $extension = pathinfo($extensionFilePath ?? $filePath, PATHINFO_EXTENSION);

        return "{$baseName}{$suffix}.{$extension}";
    }

    protected function guardAgainstInvalidTinyPlaceHolder(string $tinyPlaceholderPath): void
    {
        if (! file_exists($tinyPlaceholderPath)) {
            throw InvalidTinyJpg::doesNotExist($tinyPlaceholderPath);
        }

        if (File::getMimeType($tinyPlaceholderPath) !== 'image/jpeg') {
            throw InvalidTinyJpg::hasWrongMimeType($tinyPlaceholderPath);
        }
    }

    protected function addPropertiesToFileName(string $fileName, string $conversionName, int $width, int $height, string $extension): string
    {
        $fileName = pathinfo($fileName, PATHINFO_FILENAME);

        return "{$fileName}___{$conversionName}_{$width}_{$height}.{$extension}";
    }
}
