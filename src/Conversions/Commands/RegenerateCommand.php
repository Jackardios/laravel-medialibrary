<?php

namespace Spatie\MediaLibrary\Conversions\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
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
    {--verify-existence : With --only-missing, confirm each conversion file exists on disk (slower; one request per conversion on remote disks)}
    {--with-responsive-images : Regenerate responsive images}
    {--eager-models : Eager load the related model (avoids an N+1 for conversions registered using the model instance)}
    {--queue-connection= : Dispatch a job per media onto this queue connection (overrides media-library.queue_connection_name; point it at an async connection to offload regeneration even when the configured one is sync)}
    {--force : Force the operation to run when in production}';

    protected $description = 'Regenerate the derived images of media';

    protected MediaRepository $mediaRepository;

    protected FileManipulator $fileManipulator;

    protected array $errorMessages = [];

    public function handle(MediaRepository $mediaRepository, FileManipulator $fileManipulator): void
    {
        $this->mediaRepository = $mediaRepository;

        $this->fileManipulator = $fileManipulator;

        if (! $this->confirmToProceed()) {
            return;
        }

        $only = Arr::wrap($this->option('only'));
        $onlyMissing = (bool) $this->option('only-missing');
        $withResponsiveImages = (bool) $this->option('with-responsive-images');
        $verifyExistence = (bool) $this->option('verify-existence');

        if ($verifyExistence && ! $onlyMissing) {
            $this->warn('The --verify-existence option only has an effect together with --only-missing; ignoring it.');
            $verifyExistence = false;
        }

        // Respect the configured queue connection, just like the rest of the package: a `sync`
        // connection regenerates inline in this process, an async connection offloads one job
        // per media to the workers. `--queue-connection` overrides the connection for a single run.
        $connection = $this->resolveQueueConnection();
        $shouldDispatch = $connection !== 'sync';

        $query = $this->getMediaQueryToBeRegenerated();

        // Drive the progress bar from a cheap COUNT instead of materialising the whole table.
        $progressBar = $this->output->createProgressBar($query->count());

        if ($this->option('eager-models')) {
            $query->with('model');
        }

        if (! $shouldDispatch) {
            // The whole regeneration runs in this process; lift the execution time limit.
            set_time_limit(0);
        }

        $dispatchedJobs = 0;

        // Stream media in id-ordered chunks so memory stays flat regardless of library size.
        $query->lazyById()->each(function (Media $media) use (
            $progressBar,
            $shouldDispatch,
            $connection,
            $only,
            $onlyMissing,
            $withResponsiveImages,
            $verifyExistence,
            &$dispatchedJobs
        ) {
            try {
                if ($shouldDispatch) {
                    $this->dispatchRegenerateJob($media, $connection, $only, $onlyMissing, $withResponsiveImages, $verifyExistence);

                    $dispatchedJobs++;
                } else {
                    $this->fileManipulator->regenerateDerivedFiles(
                        $media,
                        $only,
                        $onlyMissing,
                        $withResponsiveImages,
                        $verifyExistence
                    );
                }
            } catch (Throwable $exception) {
                $this->errorMessages[$media->getKey()] = $exception->getMessage();
            }

            $progressBar->advance();
        });

        $progressBar->finish();

        $this->newLine(2);

        if (count($this->errorMessages)) {
            $this->warn($shouldDispatch
                ? 'Done queueing, but with some error messages:'
                : 'All done, but with some error messages:');

            foreach ($this->errorMessages as $mediaId => $message) {
                $this->warn("Media id {$mediaId}: `{$message}`");
            }
        }

        if ($shouldDispatch) {
            $this->info("Queued {$dispatchedJobs} media for regeneration on the '{$connection}' connection.");
            $this->info('Make sure queue workers are running to process them.');
        } else {
            $this->info('All done!');
        }
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
     * Resolve the queue connection the regeneration should run on. An explicit
     * `--queue-connection` wins; otherwise the package's `queue_connection_name` is used, falling
     * back to the application's default queue connection. A `sync` result runs inline, anything
     * else dispatches one job per media.
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
