<?php

namespace Spatie\MediaLibrary\Conversions\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\RegenerateMediaJob;
use Spatie\MediaLibrary\MediaCollections\MediaRepository;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class RegenerateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'media-library:regenerate {modelType?} {--ids=*}
    {--only=* : Regenerate specific conversions}
    {--starting-from-id= : Regenerate media with an id equal to or higher than the provided value}
    {--X|exclude-starting-id : Exclude the provided id when regenerating from a specific id}
    {--only-missing : Regenerate only missing conversions}
    {--trust-database : With --only-missing, treat a conversion as missing when the generated_conversions column does not mark it, instead of checking the disk}
    {--with-responsive-images : Regenerate responsive images}
    {--eager-models : Eager load the related model (avoids an N+1 for conversions registered using the model instance)}
    {--force : Force the operation to run when in production}
    {--queue-all : Queue one job per media that regenerates all of its conversions, even non-queued ones}
    {--queue-connection= : The queue connection for --queue-all (implies --queue-all; defaults to media-library.queue_connection_name)}';

    protected $description = 'Regenerate the derived images of media';

    protected MediaRepository $mediaRepository;

    protected FileManipulator $fileManipulator;

    protected array $errorMessages = [];

    public function handle(MediaRepository $mediaRepository, FileManipulator $fileManipulator): int
    {
        $this->mediaRepository = $mediaRepository;

        $this->fileManipulator = $fileManipulator;

        // Cast to 0, it would regenerate every media.
        $startingFromId = $this->option('starting-from-id');

        if ($startingFromId !== null && ! ctype_digit((string) $startingFromId)) {
            $this->error("The --starting-from-id option has to be a media id, `{$startingFromId}` given.");

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::SUCCESS;
        }

        $only = Arr::wrap($this->option('only'));
        $onlyMissing = (bool) $this->option('only-missing');
        $withResponsiveImages = (bool) $this->option('with-responsive-images');
        $trustDatabase = (bool) $this->option('trust-database');

        if ($trustDatabase && ! $onlyMissing) {
            $this->warn('The --trust-database option only has an effect together with --only-missing; ignoring it.');
            $trustDatabase = false;
        }

        $queueAll = $this->option('queue-all') || $this->option('queue-connection');
        $connection = $queueAll ? $this->resolveQueueConnection() : null;

        $query = $this->getMediaQueryToBeRegenerated();

        // Drive the progress bar from a cheap COUNT instead of materialising the whole table.
        $progressBar = $this->output->createProgressBar($query->count());

        if ($this->option('eager-models')) {
            $query->with('model');
        }

        if (config('media-library.queue_connection_name') === 'sync' || $connection === 'sync') {
            set_time_limit(0);
        }

        $dispatchedJobs = 0;

        // Stream media in id-ordered chunks so memory stays flat regardless of library size.
        $query->lazyById()->each(function (Media $media) use (
            $progressBar,
            $connection,
            $only,
            $onlyMissing,
            $withResponsiveImages,
            $trustDatabase,
            &$dispatchedJobs
        ) {
            try {
                if ($connection !== null) {
                    $this->dispatchRegenerateJob($media, $connection, $only, $onlyMissing, $withResponsiveImages, ! $trustDatabase);

                    $dispatchedJobs++;
                } else {
                    $this->regenerate($media, $only, $onlyMissing, $withResponsiveImages, $trustDatabase);
                }
            } catch (Throwable $exception) {
                $this->errorMessages[$media->getKey()] = $exception->getMessage();
            }

            $progressBar->advance();
        });

        $progressBar->finish();

        $this->newLine(2);

        if (count($this->errorMessages)) {
            $this->warn($connection !== null
                ? 'Done queueing, but with some error messages:'
                : 'All done, but with some error messages:');

            foreach ($this->errorMessages as $mediaId => $message) {
                $this->warn("Media id {$mediaId}: `{$message}`");
            }
        }

        if ($connection !== null) {
            $this->info("Queued {$dispatchedJobs} media for regeneration on the '{$connection}' connection.");

            if ($connection !== 'sync') {
                $this->info('Make sure queue workers are running to process them.');
            }
        } else {
            $this->info('All done!');
        }

        return count($this->errorMessages) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Regenerate one media in this process, the way conversions are created when media is added:
     * non-queued conversions run here, queued ones are dispatched to the queue.
     */
    protected function regenerate(Media $media, array $only, bool $onlyMissing, bool $withResponsiveImages, bool $trustDatabase): void
    {
        if ($onlyMissing && $trustDatabase) {
            // Decide from the generated_conversions column instead of asking the disk: hand only
            // the conversions it does not mark as generated to the regular flow.
            $missing = $media->getMediaConversionNames();

            if (count($only) > 0) {
                $missing = array_intersect($missing, $only);
            }

            $missing = array_values(array_filter(
                $missing,
                fn (string $conversionName) => ! $media->hasGeneratedConversion($conversionName)
            ));

            if ($missing === []) {
                // Nothing to convert (an empty list would mean "every conversion" below).
                if ($withResponsiveImages) {
                    $this->fileManipulator->regenerateDerivedFiles($media, $only, true, true);
                }

                return;
            }

            $only = $missing;
            $onlyMissing = false;
        }

        // Laravel would run the deferred conversions after the command, only when it succeeds, and
        // would not count their failures. Collect them apart and perform them here, with their media.
        $deferredCallbacks = app(DeferredCallbackCollection::class);
        $deferredConversions = new DeferredCallbackCollection;
        app()->instance(DeferredCallbackCollection::class, $deferredConversions);

        try {
            $this->fileManipulator->createDerivedFiles($media, $only, $onlyMissing, $withResponsiveImages);
        } finally {
            app()->instance(DeferredCallbackCollection::class, $deferredCallbacks);

            while (count($deferredConversions) > 0) {
                $callback = $deferredConversions->first();
                unset($deferredConversions[0]);
                $callback();
            }
        }
    }

    /**
     * @deprecated Use getMediaQueryToBeRegenerated(), which the command streams with lazyById().
     */
    public function getMediaToBeRegenerated(): LazyCollection
    {
        return $this->getMediaQueryToBeRegenerated()->lazyById();
    }

    /** @return Builder<Media> */
    public function getMediaQueryToBeRegenerated(): Builder
    {
        // Get this arg first as it can also be passed to the greater-than-id branch
        $modelType = $this->argument('modelType');

        $startingFromId = (int) $this->option('starting-from-id');
        if ($startingFromId !== 0) {
            $excludeStartingId = (bool) $this->option('exclude-starting-id') ?: false;

            return $this->mediaRepository->queryByIdGreaterThan($startingFromId, $excludeStartingId, is_string($modelType) ? $modelType : '');
        }

        if (is_string($modelType)) {
            return $this->mediaRepository->queryByModelType($modelType);
        }

        $mediaIds = $this->getMediaIds();
        if (count($mediaIds) > 0) {
            return $this->mediaRepository->queryByIds($mediaIds);
        }

        return $this->mediaRepository->queryAll();
    }

    protected function dispatchRegenerateJob(
        Media $media,
        string $connection,
        array $only,
        bool $onlyMissing,
        bool $withResponsiveImages,
        bool $verifyExistence
    ): void {
        $jobClass = config('media-library.jobs.regenerate_media', RegenerateMediaJob::class);

        /** @var RegenerateMediaJob $job */
        $job = (new $jobClass($media, $only, $onlyMissing, $withResponsiveImages, $verifyExistence))
            ->onConnection($connection)
            ->onQueue(config('media-library.queue_name'));

        dispatch($job);
    }

    /**
     * The queue connection for --queue-all: an explicit `--queue-connection` wins, then the
     * package's `queue_connection_name`, then the application's default connection. On `sync`
     * the jobs run right away in this process.
     */
    protected function resolveQueueConnection(): string
    {
        return (string) (
            $this->option('queue-connection')
            ?: config('media-library.queue_connection_name')
            ?: config('queue.default')
            ?: 'sync'
        );
    }

    protected function getMediaIds(): array
    {
        $mediaIds = $this->option('ids');

        if (! is_array($mediaIds)) {
            $mediaIds = explode(',', (string) $mediaIds);
        }

        if (count($mediaIds) === 1 && Str::contains((string) $mediaIds[0], ',')) {
            $mediaIds = explode(',', (string) $mediaIds[0]);
        }

        return $mediaIds;
    }
}
