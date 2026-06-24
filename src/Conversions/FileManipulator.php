<?php

namespace Spatie\MediaLibrary\Conversions;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Actions\PerformConversionAction;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Throwable;

class FileManipulator
{
    public function createDerivedFiles(
        Media $media,
        array $onlyConversionNames = [],
        bool $onlyMissing = false,
        bool $withResponsiveImages = false
    ): void {
        if (! $this->canConvertMedia($media)) {
            return;
        }

        [$queuedConversions, $conversions] = ConversionCollection::createForMedia($media)
            ->filter(function (Conversion $conversion) use ($onlyConversionNames) {
                if (count($onlyConversionNames) === 0) {
                    return true;
                }

                return in_array($conversion->getName(), $onlyConversionNames);
            })
            ->filter(fn (Conversion $conversion) => $conversion->shouldBePerformedOn($media->collection_name))
            ->partition(fn (Conversion $conversion) => $conversion->shouldBeQueued());

        $this
            ->performConversions($conversions, $media, $onlyMissing)
            ->dispatchQueuedConversions($media, $queuedConversions, $onlyMissing)
            ->generateResponsiveImages($media, $withResponsiveImages);
    }

    /**
     * Regenerate every derived file for a single media as one atomic unit of work.
     *
     * Unlike createDerivedFiles(), this does not partition conversions into queued/non-queued
     * (the whole media is the unit of work, dispatched per-media by the regenerate command), it
     * downloads the original only once and reuses it for both conversions and responsive images,
     * and it decides "missing" from the `generated_conversions` column instead of hitting storage.
     */
    public function regenerateDerivedFiles(
        Media $media,
        array $onlyConversionNames = [],
        bool $onlyMissing = false,
        bool $withResponsiveImages = false,
        bool $verifyExistence = false
    ): void {
        if (! $this->canConvertMedia($media)) {
            return;
        }

        $conversions = ConversionCollection::createForMedia($media)
            ->filter(function (Conversion $conversion) use ($onlyConversionNames) {
                if (count($onlyConversionNames) === 0) {
                    return true;
                }

                return in_array($conversion->getName(), $onlyConversionNames);
            })
            ->filter(fn (Conversion $conversion) => $conversion->shouldBePerformedOn($media->collection_name));

        $conversions = $this->rejectAlreadyGeneratedConversions($conversions, $media, $onlyMissing, $verifyExistence);

        $needsResponsiveImages = $withResponsiveImages && count($media->responsive_images) > 0;

        // Nothing to do — skip the (potentially remote) download of the original entirely.
        if ($conversions->isEmpty() && ! $needsResponsiveImages) {
            return;
        }

        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $copiedOriginalFile = app(Filesystem::class)->copyFromMediaLibrary(
                $media,
                $temporaryDirectory->path(Str::random(32).'.'.$media->extension)
            );

            // Conversions and responsive images are independent: a failure in one must not
            // prevent the other from being (re)generated. Capture both outcomes and surface
            // them together afterwards so neither failure is silently swallowed.
            $conversionException = null;
            $responsiveException = null;

            if ($conversions->isNotEmpty()) {
                try {
                    $this->performConversionsOnCopiedFile($conversions, $media, $copiedOriginalFile);
                } catch (Throwable $conversionException) {
                    // Surfaced below, after responsive images have been handled.
                }
            }

            if ($needsResponsiveImages) {
                try {
                    app(ResponsiveImageGenerator::class)->generateResponsiveImages($media, $copiedOriginalFile);
                } catch (Throwable $responsiveException) {
                    // Surfaced below.
                }
            }

            $this->throwIfRegenerationFailed($conversionException, $responsiveException);
        } finally {
            $temporaryDirectory->delete();
        }
    }

    /**
     * Surface conversion/responsive failures from regeneration. A single failure is re-thrown
     * as-is (preserving its type for queue retry handling); when both fail, they are combined
     * into one exception so neither cause is lost.
     */
    protected function throwIfRegenerationFailed(
        ?Throwable $conversionException,
        ?Throwable $responsiveException
    ): void {
        if ($conversionException !== null && $responsiveException !== null) {
            throw new RuntimeException(
                "Regenerating conversions failed ({$conversionException->getMessage()}); ".
                "regenerating responsive images also failed ({$responsiveException->getMessage()}).",
                0,
                $conversionException
            );
        }

        if ($conversionException !== null) {
            throw $conversionException;
        }

        if ($responsiveException !== null) {
            throw $responsiveException;
        }
    }

    /**
     * Remove conversions that are already generated.
     *
     * By default this trusts the `generated_conversions` column (no storage round-trips). When
     * `verifyExistence` is set, a conversion the column believes is generated is additionally
     * confirmed against the conversions disk — so files deleted out-of-band get regenerated.
     */
    protected function rejectAlreadyGeneratedConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing,
        bool $verifyExistence
    ): ConversionCollection {
        if (! $onlyMissing) {
            return $conversions;
        }

        return $conversions->reject(function (Conversion $conversion) use ($media, $verifyExistence) {
            if (! $media->hasGeneratedConversion($conversion->getName())) {
                return false;
            }

            return $verifyExistence
                ? $this->conversionFileExists($media, $conversion->getName())
                : true;
        });
    }

    public function performConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false
    ): self {
        // Filter *before* copying the original from disk: when `onlyMissing` is set and every
        // conversion already exists, there is nothing to do and downloading the original
        // (a full GET on remote disks such as S3) would be wasted work.
        $conversions = $this->filterExistingConversions($conversions, $media, $onlyMissing);

        if ($conversions->isEmpty()) {
            return $this;
        }

        $temporaryDirectory = TemporaryDirectory::create();

        try {
            $copiedOriginalFile = app(Filesystem::class)->copyFromMediaLibrary(
                $media,
                $temporaryDirectory->path(Str::random(32).'.'.$media->extension)
            );

            $this->performConversionsOnCopiedFile($conversions, $media, $copiedOriginalFile);
        } finally {
            $temporaryDirectory->delete();
        }

        return $this;
    }

    /**
     * Run the given conversions against an already-copied original, persisting the generated
     * conversions in a single write. The caller owns the temporary directory's lifecycle.
     */
    protected function performConversionsOnCopiedFile(
        ConversionCollection $conversions,
        Media $media,
        string $copiedOriginalFile
    ): void {
        $performedConversions = 0;

        try {
            foreach ($conversions as $conversion) {
                (new PerformConversionAction())->execute($conversion, $media, $copiedOriginalFile);

                $performedConversions++;
            }
        } finally {
            // Persist once for all conversions instead of once per conversion. `saveOrTouch()`
            // bumps `updated_at` even when the `generated_conversions` value is unchanged (e.g.
            // re-generating an existing conversion). The `finally` keeps already-completed
            // conversions persisted even if a later one throws.
            if ($performedConversions > 0 || $media->isDirty('generated_conversions')) {
                $media->saveOrTouch();
            }
        }
    }

    /**
     * Remove the conversions whose files already exist on the conversions disk.
     */
    protected function filterExistingConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing
    ): ConversionCollection {
        if (! $onlyMissing) {
            return $conversions;
        }

        return $conversions->reject(
            fn (Conversion $conversion) => $this->conversionFileExists($media, $conversion->getName())
        );
    }

    /**
     * Check whether a conversion file exists on the conversions disk.
     *
     * Conversions are stored on the `conversions_disk`, which may differ from the original's
     * `disk` (e.g. private originals, public conversions). `getPath()` already resolves against
     * `conversions_disk`, so the existence check must use the same disk — otherwise it looks in
     * the wrong place and never finds the file.
     */
    protected function conversionFileExists(Media $media, string $conversionName): bool
    {
        $relativePath = $media->getPath($conversionName);

        if ($rootPath = config("filesystems.disks.{$media->conversions_disk}.root")) {
            $relativePath = str_replace($rootPath, '', $relativePath);
        }

        return Storage::disk($media->conversions_disk)->exists($relativePath);
    }

    protected function dispatchQueuedConversions(
        Media $media,
        ConversionCollection $conversions,
        bool $onlyMissing = false
    ): self {
        if ($conversions->isEmpty()) {
            return $this;
        }

        $performConversionsJobClass = config(
            'media-library.jobs.perform_conversions',
            PerformConversionsJob::class
        );

        /** @var PerformConversionsJob $job */
        $job = (new $performConversionsJobClass($conversions, $media, $onlyMissing))
            ->onConnection(config('media-library.queue_connection_name'))
            ->onQueue(config('media-library.queue_name'));

        dispatch($job);

        return $this;
    }

    protected function generateResponsiveImages(Media $media, bool $withResponsiveImages): self
    {
        if (! $withResponsiveImages) {
            return $this;
        }

        if (! count($media->responsive_images)) {
            return $this;
        }

        $generateResponsiveImagesJobClass = config(
            'media-library.jobs.generate_responsive_images',
            GenerateResponsiveImagesJob::class
        );

        /** @var GenerateResponsiveImagesJob $job */
        $job = (new $generateResponsiveImagesJobClass($media))
            ->onConnection(config('media-library.queue_connection_name'))
            ->onQueue(config('media-library.queue_name'));

        dispatch($job);

        return $this;
    }

    protected function canConvertMedia(Media $media): bool
    {
        $imageGenerator = ImageGeneratorFactory::forMedia($media);

        return $imageGenerator ? true : false;
    }
}
