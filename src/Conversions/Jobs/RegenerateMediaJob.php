<?php

namespace Spatie\MediaLibrary\Conversions\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RegenerateMediaJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $deleteWhenMissingModels = true;

    public function __construct(
        protected Media $media,
        protected array $only = [],
        protected bool $onlyMissing = false,
        protected bool $withResponsiveImages = false,
        protected bool $verifyExistence = false,
    ) {}

    public function handle(FileManipulator $fileManipulator): void
    {
        $fileManipulator->regenerateDerivedFiles(
            $this->media,
            $this->only,
            $this->onlyMissing,
            $this->withResponsiveImages,
            $this->verifyExistence,
        );
    }
}
