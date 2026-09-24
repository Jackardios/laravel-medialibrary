<?php

use Illuminate\Queue\Jobs\SyncJob;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\Conversions\Jobs\RegenerateMediaJob;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;

it('discards the job of a media that was deleted before it ran', function (string $jobClass) {
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection();

    $command = match ($jobClass) {
        PerformConversionsJob::class => new PerformConversionsJob(ConversionCollection::createForMedia($media), $media),
        default => new $jobClass($media),
    };

    // Queue the job the way a queue connection does, then run it once the media was deleted.
    $queue = app('queue')->connection('sync');
    $payload = (fn () => $this->createPayload($command, 'default'))->call($queue);

    $media->delete();

    $job = new SyncJob(app(), $payload, 'sync', 'default');
    $job->fire();

    expect($job->isDeleted())->toBeTrue()
        ->and($job->hasFailed())->toBeFalse();
})->with([
    GenerateResponsiveImagesJob::class,
    PerformConversionsJob::class,
    RegenerateMediaJob::class,
]);
