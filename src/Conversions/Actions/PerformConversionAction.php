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
        if (! $this->perform($conversion, $media, $copiedOriginalFile)) {
            return;
        }

        $media->markAsConversionGenerated($conversion->getName());

        event(new ConversionHasBeenCompletedEvent($media, $conversion));
    }

    /**
     * Generate the conversion and store it, without recording it on the media or announcing it:
     * FileManipulator records a whole batch with one write and then fires the completed events.
     *
     * @return bool whether a conversion file was stored
     */
    public function perform(
        Conversion $conversion,
        Media $media,
        string $copiedOriginalFile
    ): bool {
        $imageGenerator = ImageGeneratorFactory::forMedia($media);

        $imageFile = $imageGenerator->convert($copiedOriginalFile, $conversion);

        if (! $imageFile) {
            return false;
        }

        event(new ConversionWillStartEvent($media, $conversion, $imageFile));

        $manipulationResult = (new PerformManipulationsAction)->execute($media, $conversion, $imageFile);

        if (! $manipulationResult) {
            return false;
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

        return true;
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
