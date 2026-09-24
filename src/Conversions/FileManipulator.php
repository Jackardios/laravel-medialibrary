<?php

namespace Spatie\MediaLibrary\Conversions;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\Actions\PerformConversionAction;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
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
        bool $withResponsiveImages = false,
        bool $queueAll = false,
    ): void {
        if (! $this->canConvertMedia($media)) {
            return;
        }

        $allConversions = ConversionCollection::createForMedia($media)
            ->filter(function (Conversion $conversion) use ($onlyConversionNames) {
                if (count($onlyConversionNames) === 0) {
                    return true;
                }

                return in_array($conversion->getName(), $onlyConversionNames);
            })
            ->filter(fn (Conversion $conversion) => $conversion->shouldBePerformedOn($media->collection_name));

        if ($queueAll) {
            $this
                ->dispatchQueuedConversions($media, $allConversions, $onlyMissing)
                ->generateResponsiveImages($media, $withResponsiveImages);

            return;
        }

        [$deferredConversions, $remaining] = $allConversions->partition(
            fn (Conversion $conversion) => $conversion->shouldBeDeferred()
        );

        [$queuedConversions, $conversions] = $remaining->partition(
            fn (Conversion $conversion) => $conversion->shouldBeQueued()
        );

        $this
            ->performConversions($conversions, $media, $onlyMissing)
            ->performDeferredConversions($deferredConversions, $media, $onlyMissing)
            ->dispatchQueuedConversions($media, $queuedConversions, $onlyMissing)
            ->generateResponsiveImages($media, $withResponsiveImages);
    }

    /**
     * Regenerate every derived file of a media in one pass.
     *
     * Unlike createDerivedFiles(), this does not partition conversions into queued/non-queued
     * (the whole media is the unit of work, see `media-library:regenerate --queue-all`), and it
     * downloads the original only once and reuses it for both conversions and responsive images.
     *
     * With `onlyMissing`, a conversion is missing when the `generated_conversions` column does
     * not mark it, or, with `verifyExistence`, when its file is not on the conversions disk.
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

        $needsResponsiveImages = $withResponsiveImages && $this->hasResponsiveImagesOfOriginal($media);

        // Nothing to do — skip the (potentially remote) download of the original entirely.
        if ($conversions->isEmpty() && ! $needsResponsiveImages) {
            if ($media->isDirty('generated_conversions')) {
                $media->save();
            }

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
     * By default this trusts the `generated_conversions` column (no storage round-trips). With
     * `verifyExistence` the conversions disk decides, as for createDerivedFiles(): files deleted
     * out-of-band get regenerated, and a file that exists but lost its mark gets the mark back.
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
            if (! $verifyExistence) {
                return $media->hasGeneratedConversion($conversion->getName());
            }

            if (! $this->conversionFileExists($media, $conversion->getName())) {
                return false;
            }

            if (! $media->hasGeneratedConversion($conversion->getName())) {
                $media->markAsConversionGenerated($conversion->getName(), persist: false);
            }

            return true;
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
            try {
                $copiedOriginalFile = app(Filesystem::class)->copyFromMediaLibrary(
                    $media,
                    $temporaryDirectory->path(Str::random(32).'.'.$media->extension)
                );
            } catch (FileDoesNotExist) {
                // Without an original there is nothing to convert, e.g. when regenerating a
                // library that has files missing.
                return $this;
            }

            if (filesize($copiedOriginalFile) === 0) {
                return $this;
            }

            $this->performConversionsOnCopiedFile($conversions, $media, $copiedOriginalFile);
        } finally {
            $temporaryDirectory->delete();
        }

        return $this;
    }

    protected function performDeferredConversions(
        ConversionCollection $conversions,
        Media $media,
        bool $onlyMissing = false
    ): self {
        if ($conversions->isEmpty()) {
            return $this;
        }

        defer(fn () => $this->performConversions($conversions, $media, $onlyMissing));

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
        $action = new PerformConversionAction;
        $completedConversions = [];
        $failure = null;

        try {
            foreach ($conversions as $conversion) {
                if ($action->perform($conversion, $media, $copiedOriginalFile)) {
                    $media->markAsConversionGenerated($conversion->getName(), persist: false);

                    $completedConversions[] = $conversion;
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        // Persist once for all conversions instead of once per conversion, including the ones
        // that completed before a later one failed. `saveOrTouch()` bumps `updated_at` even when
        // `generated_conversions` is unchanged (re-generating an existing conversion).
        if ($completedConversions !== [] || $media->isDirty('generated_conversions')) {
            $media->saveOrTouch();
        }

        // Announce the conversions only once they are recorded, so listeners that read the
        // media from the database (or refresh it) see them as generated.
        foreach ($completedConversions as $conversion) {
            event(new ConversionHasBeenCompletedEvent($media, $conversion));
        }

        if ($failure !== null) {
            throw $failure;
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
     * Check whether a conversion file exists on the conversions disk, which may differ from the
     * original's `disk` (e.g. private originals, public conversions).
     */
    protected function conversionFileExists(Media $media, string $conversionName): bool
    {
        return Storage::disk($media->conversions_disk)->exists($media->getPathRelativeToRoot($conversionName));
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

        config('media-library.queue_conversions_after_database_commit')
            ? dispatch($job)->afterCommit()
            : dispatch($job);

        return $this;
    }

    protected function generateResponsiveImages(Media $media, bool $withResponsiveImages): self
    {
        if (! $withResponsiveImages) {
            return $this;
        }

        if (! $this->hasResponsiveImagesOfOriginal($media)) {
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

        config('media-library.queue_conversions_after_database_commit')
            ? dispatch($job)->afterCommit()
            : dispatch($job);

        return $this;
    }

    /**
     * Responsive images of the original are only regenerated for media that has them. Those of
     * conversions are generated along with their conversion.
     */
    protected function hasResponsiveImagesOfOriginal(Media $media): bool
    {
        return array_key_exists('media_library_original', $media->responsive_images);
    }

    protected function canConvertMedia(Media $media): bool
    {
        $imageGenerator = ImageGeneratorFactory::forMedia($media);

        return $imageGenerator ? true : false;
    }
}
