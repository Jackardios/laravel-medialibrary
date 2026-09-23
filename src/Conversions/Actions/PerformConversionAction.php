<?php

namespace Spatie\MediaLibrary\Conversions\Actions;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\ResponsiveImages\ResponsiveImageGenerator;

class PerformConversionAction
{
    public function execute(
        Conversion $conversion,
        Media $media,
        string $copiedOriginalFile
    ): void {
        $imageGenerator = ImageGeneratorFactory::forMedia($media);

        $imageFile = $imageGenerator->convert($copiedOriginalFile, $conversion);

        if (! $imageFile) {
            return;
        }

        event(new ConversionWillStartEvent($media, $conversion, $imageFile));

        $manipulationResult = (new PerformManipulationsAction)->execute($media, $conversion, $imageFile);

        if (! $manipulationResult) {
            return;
        }

        $newFileName = $conversion->getConversionFile($media);

        // Without manipulations the result is the original itself, which the other conversions
        // (and the responsive images) of this media still need: copy it instead of moving it.
        $renamedFile = $manipulationResult === $copiedOriginalFile
            ? $this->copyInLocalDirectory($manipulationResult, $newFileName)
            : $this->renameInLocalDirectory($manipulationResult, $newFileName);

        if ($conversion->shouldGenerateResponsiveImages()) {
            /** @var ResponsiveImageGenerator $responsiveImageGenerator */
            $responsiveImageGenerator = app(ResponsiveImageGenerator::class);

            $responsiveImageGenerator->generateResponsiveImagesForConversion(
                $media,
                $conversion,
                $renamedFile
            );
        }

        app(Filesystem::class)->copyToMediaLibrary($renamedFile, $media, 'conversions');

        // Mark the conversion in memory only; the caller (FileManipulator) persists once
        // after all conversions for this media have been generated.
        $media->markAsConversionGenerated($conversion->getName(), persist: false);

        event(new ConversionHasBeenCompletedEvent($media, $conversion));
    }

    protected function copyInLocalDirectory(
        string $fileNameWithDirectory,
        string $newFileNameWithoutDirectory
    ): string {
        $targetFile = pathinfo($fileNameWithDirectory, PATHINFO_DIRNAME).'/'.$newFileNameWithoutDirectory;

        copy($fileNameWithDirectory, $targetFile);

        return $targetFile;
    }

    protected function renameInLocalDirectory(
        string $fileNameWithDirectory,
        string $newFileNameWithoutDirectory
    ): string {
        $targetFile = pathinfo($fileNameWithDirectory, PATHINFO_DIRNAME).'/'.$newFileNameWithoutDirectory;

        rename($fileNameWithDirectory, $targetFile);

        return $targetFile;
    }
}
