<?php

namespace Spatie\MediaLibrary\MediaCollections;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Exceptions\DiskCannotBeAccessed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\File;
use Spatie\MediaLibrary\Support\FileNamer\FileNamer;
use Spatie\MediaLibrary\Support\FileRemover\FileRemoverFactory;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Spatie\MediaLibrary\Support\RemoteFile;

class Filesystem
{
    protected array $customRemoteHeaders = [];

    public function __construct(
        protected Factory $filesystem
    ) {}

    public function add(string $file, Media $media, ?string $targetFileName = null): bool
    {
        try {
            $this->copyToMediaLibrary($file, $media, null, $targetFileName);
        } catch (DiskCannotBeAccessed $exception) {
            return false;
        }

        event(new MediaHasBeenAddedEvent($media));

        app(FileManipulator::class)->createDerivedFiles($media);

        return true;
    }

    public function addRemote(RemoteFile $file, Media $media, ?string $targetFileName = null): bool
    {
        try {
            $this->copyToMediaLibraryFromRemote($file, $media, null, $targetFileName);
        } catch (DiskCannotBeAccessed $exception) {
            return false;
        }

        event(new MediaHasBeenAddedEvent($media));

        app(FileManipulator::class)->createDerivedFiles($media);

        return true;
    }

    public function prepareCopyFileOnDisk(RemoteFile $file, Media $media, string $destination): void
    {
        $this->copyFileOnDisk($file->getKey(), $destination, $media->disk);
    }

    public function copyToMediaLibraryFromRemote(RemoteFile $file, Media $media, ?string $type = null, ?string $targetFileName = null): void
    {
        $destinationFileName = $targetFileName ?: $file->getFilename();

        $destination = $this->getMediaDirectory($media, $type).$destinationFileName;

        $diskDriverName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->getConversionsDiskDriverName()
            : $media->getDiskDriverName();

        if ($this->shouldCopyFileOnDisk($file, $media, $diskDriverName)) {
            $this->prepareCopyFileOnDisk($file, $media, $destination);

            return;
        }

        $storage = Storage::disk($file->getDisk());

        $customHeaders = $media->getCustomHeaders();

        if (in_array($type, ['conversions', 'responsiveImages'])) {
            unset($customHeaders['ContentType']);
        }

        $headers = $diskDriverName === 'local'
            ? []
            : $this->getRemoteHeadersForFile(
                $file->getKey(),
                $customHeaders,
                $storage->mimeType($file->getKey())
            );

        $this->streamFileToDisk(
            $storage->getDriver()->readStream($file->getKey()),
            $destination,
            $media->disk,
            $headers
        );
    }

    protected function shouldCopyFileOnDisk(RemoteFile $file, Media $media, string $diskDriverName): bool
    {
        if ($file->getDisk() !== $media->disk) {
            return false;
        }

        if ($diskDriverName === 'local') {
            return true;
        }

        if (count($media->getCustomHeaders()) > 0) {
            return false;
        }

        if ((is_countable(config('media-library.remote.extra_headers')) ? count(config('media-library.remote.extra_headers')) : 0) > 0) {
            return false;
        }

        return true;
    }

    protected function copyFileOnDisk(string $file, string $destination, string $disk): void
    {
        // Without `throw` in the disk config a failed copy only returns false. The caller deletes
        // the source afterwards (unless preservingOriginal()), so a silent failure loses the file.
        if (! $this->filesystem->disk($disk)->copy($file, $destination)) {
            throw DiskCannotBeAccessed::create($disk);
        }
    }

    protected function streamFileToDisk($stream, string $destination, string $disk, array $headers): void
    {
        $this->filesystem->disk($disk)
            ->getDriver()->writeStream(
                $destination,
                $stream,
                $headers
            );
    }

    public function copyToMediaLibrary(string $pathToFile, Media $media, ?string $type = null, ?string $targetFileName = null): void
    {
        $destinationFileName = $targetFileName ?: pathinfo($pathToFile, PATHINFO_BASENAME);

        $destination = $this->getMediaDirectory($media, $type).$destinationFileName;

        $file = fopen($pathToFile, 'r');

        if ($file === false) {
            throw FileDoesNotExist::create($pathToFile);
        }

        $diskName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->conversions_disk
            : $media->disk;

        $diskDriverName = (in_array($type, ['conversions', 'responsiveImages']))
            ? $media->getConversionsDiskDriverName()
            : $media->getDiskDriverName();

        if ($diskDriverName === 'local') {
            $success = $this->filesystem
                ->disk($diskName)
                ->put($destination, $file);

            fclose($file);

            if (! $success) {
                throw DiskCannotBeAccessed::create($diskName);
            }

            return;
        }

        $customHeaders = $media->getCustomHeaders();

        if (in_array($type, ['conversions', 'responsiveImages'])) {
            unset($customHeaders['ContentType']);
        }

        $success = $this->filesystem
            ->disk($diskName)
            ->put(
                $destination,
                $file,
                $this->getRemoteHeadersForFile($pathToFile, $customHeaders),
            );

        if (is_resource($file)) {
            fclose($file);
        }

        if (! $success) {
            throw DiskCannotBeAccessed::create($diskName);
        }
    }

    public function addCustomRemoteHeaders(array $customRemoteHeaders): void
    {
        $this->customRemoteHeaders = $customRemoteHeaders;
    }

    public function getRemoteHeadersForFile(
        string $file,
        array $mediaCustomHeaders = [],
        ?string $mimeType = null
    ): array {
        $mimeTypeHeader = ['ContentType' => $mimeType ?: File::getMimeType($file)];

        $extraHeaders = config('media-library.remote.extra_headers');

        return array_merge(
            $mimeTypeHeader,
            $extraHeaders,
            $this->customRemoteHeaders,
            $mediaCustomHeaders
        );
    }

    public function getStream(Media $media)
    {
        $sourceFile = $this->getMediaDirectory($media).$media->file_name;

        return $this->filesystem->disk($media->disk)->readStream($sourceFile);
    }

    public function getConversionStream(Media $media, string $conversion)
    {
        $sourceFile = $media->getPathRelativeToRoot($conversion);

        return $this->filesystem->disk($media->conversions_disk)->readStream($sourceFile);
    }

    public function copyFromMediaLibrary(Media $media, string $targetFile): string
    {
        $stream = $this->getStream($media);

        // Without this, a missing original would be copied as an empty file and fail later
        // with an unrelated "could not load image" error.
        if (! is_resource($stream)) {
            throw FileDoesNotExist::create($this->getMediaDirectory($media).$media->file_name);
        }

        try {
            file_put_contents($targetFile, $stream);
        } finally {
            fclose($stream);
        }

        return $targetFile;
    }

    public function removeAllFiles(Media $media): void
    {
        $fileRemover = FileRemoverFactory::create($media);

        $fileRemover->removeAllFiles($media);
    }

    public function removeFile(Media $media, string $path, ?string $disk = null): void
    {
        $fileRemover = FileRemoverFactory::create($media);

        $fileRemover->removeFile($path, $disk ?? $media->disk);
    }

    public function removeResponsiveImages(Media $media, string $conversionName = 'media_library_original'): void
    {
        /** @var FileNamer $fileNamer */
        $fileNamer = app(config('media-library.file_namer'));
        $mediaFilename = $fileNamer->responsiveFileName($media->name);

        $responsiveImagesDirectory = $this->getResponsiveImagesDirectory($media);

        // Responsive images are stored on the `conversions_disk` (see copyToMediaLibrary),
        // which may differ from the original's `disk` — list and delete from the same disk.
        $allFilePaths = $this->filesystem->disk($media->conversions_disk)->allFiles($responsiveImagesDirectory);

        $responsiveImagePaths = array_filter(
            $allFilePaths,
            static fn (string $path) => Str::contains($path, $mediaFilename.'___'.$conversionName)
        );

        $this->filesystem->disk($media->conversions_disk)->delete($responsiveImagePaths);
    }

    public function syncFileNames(Media $media): void
    {
        $this->renameMediaFile($media);

        $this->renameConversionFiles($media);

        $this->renameResponsiveImageFiles($media);
    }

    public function syncMediaPath(Media $media): void
    {
        $factory = PathGeneratorFactory::create($media);

        $oldMedia = (clone $media)->fill($media->getOriginal());

        $oldPath = $factory->getPath($oldMedia);
        $newPath = $factory->getPath($media);

        if ($oldPath !== $newPath) {
            $this->moveDirectory($media->disk, $oldPath, $newPath);
        }

        $separateConversionsDisk = $media->conversions_disk !== $media->disk;

        // Conversions and responsive images live on the conversions disk. Unless they were
        // already moved along with the media directory, move them separately.
        foreach ([
            [$factory->getPathForConversions($oldMedia), $factory->getPathForConversions($media)],
            [$factory->getPathForResponsiveImages($oldMedia), $factory->getPathForResponsiveImages($media)],
        ] as [$oldDerivedPath, $newDerivedPath]) {
            if ($oldDerivedPath === $newDerivedPath) {
                continue;
            }

            if (! $separateConversionsDisk && $oldPath !== $newPath && str_starts_with($oldDerivedPath, $oldPath)) {
                continue;
            }

            $this->moveDirectory($media->conversions_disk, $oldDerivedPath, $newDerivedPath);
        }

        // Don't leave the emptied media directory behind on the conversions disk.
        if ($separateConversionsDisk && $oldPath !== $newPath) {
            $conversionsDisk = $this->filesystem->disk($media->conversions_disk);

            if ($conversionsDisk->directoryExists($oldPath) && $conversionsDisk->allFiles($oldPath) === []) {
                $conversionsDisk->deleteDirectory($oldPath);
            }
        }
    }

    protected function moveDirectory(string $diskName, string $oldPath, string $newPath): void
    {
        $disk = $this->filesystem->disk($diskName);

        // Object storage has no directories to rename: move every file.
        if (config("filesystems.disks.{$diskName}.driver") === 's3') {
            foreach ($disk->allFiles($oldPath) as $file) {
                $disk->move($file, $newPath.Str::after($file, $oldPath));
            }

            return;
        }

        if ($disk->directoryExists($oldPath)) {
            $disk->move($oldPath, $newPath);
        }
    }

    protected function renameMediaFile(Media $media): void
    {
        $newFileName = $media->file_name;
        $oldFileName = $media->getOriginal('file_name');

        $mediaDirectory = $this->getMediaDirectory($media);

        $oldFile = "{$mediaDirectory}/{$oldFileName}";
        $newFile = "{$mediaDirectory}/{$newFileName}";

        $this->filesystem->disk($media->disk)->move($oldFile, $newFile);
    }

    protected function renameConversionFiles(Media $media): void
    {
        $mediaWithOldFileName = config('media-library.media_model')::find($media->getKey());
        $mediaWithOldFileName->file_name = $mediaWithOldFileName->getOriginal('file_name');

        $conversionDirectory = $this->getConversionDirectory($media);

        $conversionCollection = ConversionCollection::createForMedia($media);

        foreach ($media->getMediaConversionNames() as $conversionName) {
            $conversion = $conversionCollection->getByName($conversionName);

            $oldFile = $conversionDirectory.$conversion->getConversionFile($mediaWithOldFileName);
            $newFile = $conversionDirectory.$conversion->getConversionFile($media);

            $disk = $this->filesystem->disk($media->conversions_disk);

            // A media conversion file might be missing, waiting to be generated, failed etc.
            if (! $disk->exists($oldFile)) {
                continue;
            }

            $disk->move($oldFile, $newFile);
        }
    }

    protected function renameResponsiveImageFiles(Media $media): void
    {
        $responsiveImages = $media->responsive_images;

        if (empty($responsiveImages)) {
            return;
        }

        $oldBase = pathinfo($media->getOriginal('file_name'), PATHINFO_FILENAME);
        $newBase = pathinfo($media->file_name, PATHINFO_FILENAME);

        if ($oldBase === $newBase) {
            return;
        }

        $directory = $this->getResponsiveImagesDirectory($media);
        $disk = $this->filesystem->disk($media->conversions_disk);

        foreach ($responsiveImages as $conversionName => $properties) {
            if (! isset($properties['urls']) || ! is_array($properties['urls'])) {
                continue;
            }

            foreach ($properties['urls'] as $index => $fileName) {
                // Responsive file names are `{base}___{conversion}_{width}_{height}.{ext}`. Split on the
                // last `___` (matching ResponsiveImage::stringBetween) so a base that itself contains `___`
                // is handled correctly.
                $separatorPosition = strrpos($fileName, '___');

                if ($separatorPosition === false) {
                    continue;
                }

                $newFileName = $newBase.substr($fileName, $separatorPosition);

                $oldFile = $directory.$fileName;
                $newFile = $directory.$newFileName;

                // The physical file might be missing (failed generation, manual deletion, etc.). We still
                // rewrite the stored name below so the srcset stays consistent and a later regenerate can
                // recreate the file; leaving the stale name would keep the srcset broken forever.
                if ($disk->exists($oldFile)) {
                    $disk->move($oldFile, $newFile);
                }

                $responsiveImages[$conversionName]['urls'][$index] = $newFileName;
            }
        }

        $media->responsive_images = $responsiveImages;
    }

    public function getMediaDirectory(Media $media, ?string $type = null): string
    {
        $directory = null;
        $pathGenerator = PathGeneratorFactory::create($media);

        if (! $type) {
            $directory = $pathGenerator->getPath($media);
        }

        if ($type === 'conversions') {
            $directory = $pathGenerator->getPathForConversions($media);
        }

        if ($type === 'responsiveImages') {
            $directory = $pathGenerator->getPathForResponsiveImages($media);
        }

        return $directory;
    }

    public function getConversionDirectory(Media $media): string
    {
        return $this->getMediaDirectory($media, 'conversions');
    }

    public function getResponsiveImagesDirectory(Media $media): string
    {
        return $this->getMediaDirectory($media, 'responsiveImages');
    }
}
