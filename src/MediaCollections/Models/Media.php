<?php

namespace Spatie\MediaLibrary\MediaCollections\Models;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use Spatie\MediaLibrary\MediaCollections\Exceptions\MediaCannotBeUpdated;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\HtmlableMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\CustomMediaProperties;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\IsSorted;
use Spatie\MediaLibrary\ResponsiveImages\RegisteredResponsiveImages;
use Spatie\MediaLibrary\Support\ContentDisposition;
use Spatie\MediaLibrary\Support\File;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGeneratorFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;
use Throwable;
use WeakMap;

/**
 * @property string $uuid
 * @property string $model_type
 * @property string|int $model_id
 * @property string $collection_name
 * @property string $name
 * @property string $file_name
 * @property string $mime_type
 * @property string $disk
 * @property string $conversions_disk
 * @property string $type
 * @property string $extension
 * @property-read string $human_readable_size
 * @property-read string $preview_url
 * @property-read string $original_url
 * @property int $size
 * @property ?int $order_column
 * @property array $manipulations
 * @property array $custom_properties
 * @property array $generated_conversions
 * @property array $responsive_images
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class Media extends Model implements Attachable, Htmlable, Responsable
{
    use CustomMediaProperties;
    use HasUuid;
    use IsSorted;

    protected $table = 'media';

    public const TYPE_OTHER = 'other';

    protected $guarded = [];

    protected $appends = ['original_url', 'preview_url'];

    protected $casts = [
        'manipulations' => 'array',
        'custom_properties' => 'array',
        'generated_conversions' => 'array',
        'responsive_images' => 'array',
    ];

    protected int $streamChunkSize = (1024 * 1024); // default to 1MB chunks.

    /**
     * How many {@see removingFilesAfterCommit()} calls are running.
     */
    private static int $removingFilesAfterCommit = 0;

    /**
     * Conversion collections memoized by {@see getConversionCollection()}.
     *
     * Kept outside the instance on purpose: a WeakMap entry is not copied by
     * `clone`, never ends up in `serialize()`/`toArray()`, and goes away with the
     * media object (on the next cycle collection, since the collection holds its
     * media).
     *
     * @var WeakMap<self, array{0: array<string, mixed>, 1: ConversionCollection}>|null
     */
    private static ?WeakMap $conversionCollections = null;

    /**
     * How many media keep their conversion collection. The urls of one media are mostly
     * built together, so a few are enough.
     */
    protected const MEMOIZED_CONVERSION_COLLECTIONS = 16;

    /** @phpstan-ignore method.childReturnType */
    public function newCollection(array $models = []): MediaCollection
    {
        return new MediaCollection($models); // @phpstan-ignore argument.type
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function getFullUrl(string $conversionName = ''): string
    {
        return url($this->getUrl($conversionName));
    }

    public function getUrl(string $conversionName = ''): string
    {
        $urlGenerator = UrlGeneratorFactory::createForMedia($this, $conversionName);

        return $urlGenerator->getUrl();
    }

    public function getTemporaryUrl(?DateTimeInterface $expiration = null, string $conversionName = '', array $options = []): string
    {
        $expiration = $expiration ?: now()->addMinutes(config('media-library.temporary_url_default_lifetime'));
        $urlGenerator = $this->getUrlGenerator($conversionName);

        return $urlGenerator->getTemporaryUrl($expiration, $options);
    }

    public function getPath(string $conversionName = ''): string
    {
        $urlGenerator = $this->getUrlGenerator($conversionName);

        return $urlGenerator->getPath();
    }

    public function getPathRelativeToRoot(string $conversionName = ''): string
    {
        return $this->getUrlGenerator($conversionName)->getPathRelativeToRoot();
    }

    public function getUrlGenerator(string $conversionName): UrlGenerator
    {
        return UrlGeneratorFactory::createForMedia($this, $conversionName);
    }

    public function getAvailableUrl(array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $this->hasGeneratedConversion($conversionName)) {
                continue;
            }

            return $this->getUrl($conversionName);
        }

        return $this->getUrl();
    }

    public function getAvailableTemporaryUrl(array $conversionNames, ?DateTimeInterface $expiration = null, array $options = []): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $this->hasGeneratedConversion($conversionName)) {
                continue;
            }

            return $this->getTemporaryUrl($expiration, $conversionName, $options);
        }

        return $this->getTemporaryUrl($expiration, '', $options);
    }

    public function getDownloadFilename(): string
    {
        return $this->file_name;
    }

    public function getAvailableFullUrl(array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $this->hasGeneratedConversion($conversionName)) {
                continue;
            }

            return $this->getFullUrl($conversionName);
        }

        return $this->getFullUrl();
    }

    public function getAvailablePath(array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $this->hasGeneratedConversion($conversionName)) {
                continue;
            }

            return $this->getPath($conversionName);
        }

        return $this->getPath();
    }

    public function getAvailablePathRelativeToRoot(array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if (! $this->hasGeneratedConversion($conversionName)) {
                continue;
            }

            return $this->getPathRelativeToRoot($conversionName);
        }

        return $this->getPathRelativeToRoot();
    }

    protected function type(): Attribute
    {
        return Attribute::get(
            function () {
                $type = $this->getTypeFromExtension();

                if ($type !== self::TYPE_OTHER) {
                    return $type;
                }

                return $this->getTypeFromMime();
            }
        );
    }

    public function getTypeFromExtension(): string
    {
        $imageGenerator = ImageGeneratorFactory::forExtension($this->extension);

        return $imageGenerator
            ? $imageGenerator->getType()
            : static::TYPE_OTHER;
    }

    public function getTypeFromMime(): string
    {
        $imageGenerator = ImageGeneratorFactory::forMimeType($this->mime_type);

        return $imageGenerator
            ? $imageGenerator->getType()
            : static::TYPE_OTHER;
    }

    protected function extension(): Attribute
    {
        return Attribute::get(fn () => pathinfo($this->file_name, PATHINFO_EXTENSION));
    }

    protected function humanReadableSize(): Attribute
    {
        return Attribute::get(fn () => File::getHumanReadableSize($this->size));
    }

    /**
     * Rows created outside the FileAdder can lack a conversions disk: their conversions and
     * responsive images live on the original's disk, not on the application's default disk.
     */
    protected function conversionsDisk(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value ?: $this->disk);
    }

    public function getDiskDriverName(): string
    {
        return strtolower(config("filesystems.disks.{$this->disk}.driver"));
    }

    public function getConversionsDiskDriverName(): string
    {
        return strtolower(config("filesystems.disks.{$this->conversions_disk}.driver"));
    }

    public function hasCustomProperty(string $propertyName): bool
    {
        return Arr::has($this->custom_properties, $propertyName);
    }

    /**
     * Get the value of custom property with the given name.
     *
     * @param  mixed  $default
     */
    public function getCustomProperty(string $propertyName, $default = null): mixed
    {
        return Arr::get($this->custom_properties, $propertyName, $default);
    }

    /**
     * @param  mixed  $value
     * @return $this
     */
    public function setCustomProperty(string $name, $value): self
    {
        $customProperties = $this->custom_properties;

        Arr::set($customProperties, $name, $value);

        $this->custom_properties = $customProperties;

        return $this;
    }

    /**
     * @return $this
     */
    public function forgetCustomProperty(string $name): self
    {
        $customProperties = $this->custom_properties;

        Arr::forget($customProperties, $name);

        $this->custom_properties = $customProperties;

        return $this;
    }

    /**
     * The conversions registered for this media, built once while its attributes are unchanged.
     *
     * Owners that register conversions using the model instance are never memoized, because
     * their conversions may follow the owner's state. The returned collection is shared, so
     * callers must not change it or its conversions; processing uses
     * {@see ConversionCollection::createForMedia()}.
     */
    public function getConversionCollection(): ConversionCollection
    {
        $memo = self::$conversionCollections ??= new WeakMap;
        $fingerprint = $this->getAttributes();

        $entry = $memo[$this] ?? null;

        if ($entry !== null && $entry[0] === $fingerprint) {
            return $entry[1];
        }

        $conversions = ConversionCollection::createForMedia($this);

        if ($conversions->dependsOnModelInstance()) {
            unset($memo[$this]);

            return $conversions;
        }

        $memo[$this] = [$fingerprint, $conversions];

        if (count($memo) > static::MEMOIZED_CONVERSION_COLLECTIONS) {
            // Forget the media that was memoized first.
            foreach ($memo as $media => $entry) {
                unset($memo[$media]);

                break;
            }
        }

        return $conversions;
    }

    public function getMediaConversionNames(): array
    {
        return $this->getConversionCollection()->map(fn (Conversion $conversion) => $conversion->getName())->toArray();
    }

    public function getGeneratedConversions(): Collection
    {
        return collect($this->generated_conversions ?? []);
    }

    /**
     * @return $this
     */
    public function markAsConversionGenerated(string $conversionName, bool $persist = true): self
    {
        $generatedConversions = $this->generated_conversions;

        Arr::set($generatedConversions, $conversionName, true);

        $this->generated_conversions = $generatedConversions;

        // When generating several conversions for the same media in one pass, callers can
        // defer persistence (`$persist = false`) and issue a single `saveOrTouch()` afterwards
        // instead of one write per conversion.
        if ($persist) {
            $this->saveOrTouch();
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function markAsConversionNotGenerated(string $conversionName): self
    {
        $generatedConversions = $this->generated_conversions;

        Arr::set($generatedConversions, $conversionName, false);

        $this->generated_conversions = $generatedConversions;

        $this->saveOrTouch();

        return $this;
    }

    public function hasGeneratedConversion(string $conversionName): bool
    {
        $generatedConversions = $this->generated_conversions;

        return Arr::get($generatedConversions, $conversionName, false);
    }

    /**
     * @return $this
     */
    public function setStreamChunkSize(int $chunkSize): self
    {
        $this->streamChunkSize = $chunkSize;

        return $this;
    }

    public function toResponse($request, string $conversion = ''): StreamedResponse
    {
        return $this->buildResponse($request, 'attachment', $conversion);
    }

    public function toInlineResponse($request, string $conversion = ''): StreamedResponse
    {
        return $this->buildResponse($request, 'inline', $conversion);
    }

    public function toAvailableResponse($request, array $conversionNames): StreamedResponse
    {
        return $this->toResponse($request, $this->findFirstAvailableConversion($conversionNames));
    }

    public function toAvailableInlineResponse($request, array $conversionNames): StreamedResponse
    {
        return $this->toInlineResponse($request, $this->findFirstAvailableConversion($conversionNames));
    }

    private function findFirstAvailableConversion(array $conversionNames): string
    {
        foreach ($conversionNames as $conversionName) {
            if ($this->hasGeneratedConversion($conversionName)) {
                return $conversionName;
            }
        }

        return '';
    }

    private function buildResponse($request, string $contentDispositionType, string $conversion = ''): StreamedResponse
    {
        // A conversion is sent under its own file name and with the mime type of its format.
        $conversionPath = $conversion !== '' ? $this->getPathRelativeToRoot($conversion) : null;

        $filename = $conversionPath !== null ? basename($conversionPath) : $this->getDownloadFilename();

        // Open the file before sending the headers, so a missing file fails the response instead
        // of a 200 with a broken body.
        $stream = $this->stream($conversion);

        if (! is_resource($stream)) {
            throw FileDoesNotExist::create($conversionPath ?? $this->getPathRelativeToRoot());
        }

        $size = $conversionPath !== null
            ? Storage::disk($this->conversions_disk)->size($conversionPath)
            : $this->size;

        $mimeType = $conversionPath !== null
            ? MimeTypes::getDefault()->getMimeTypes(pathinfo($conversionPath, PATHINFO_EXTENSION))[0] ?? 'application/octet-stream'
            : $this->mime_type;

        $downloadHeaders = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Content-Disposition' => ContentDisposition::header($contentDispositionType, $filename),
            'Pragma' => 'public',
        ];

        return response()->stream(function () use ($stream) {
            while (! feof($stream)) {
                echo fread($stream, $this->streamChunkSize);
                flush();
            }

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $downloadHeaders);
    }

    public function getResponsiveImageUrls(string $conversionName = ''): array
    {
        return $this->responsiveImages($conversionName)->getUrls();
    }

    public function hasResponsiveImages(string $conversionName = ''): bool
    {
        return $this->responsiveImages($conversionName)->files->isNotEmpty();
    }

    public function getSrcset(string $conversionName = ''): string
    {
        return $this->responsiveImages($conversionName)->getSrcset();
    }

    protected function previewUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->hasGeneratedConversion('preview') ? $this->getUrl('preview') : '',
        );
    }

    protected function originalUrl(): Attribute
    {
        return Attribute::get(fn () => $this->getUrl());
    }

    /**
     * Media deleted while the callback runs lose their files only once the open transactions of their
     * database connection are committed. Rolling them back brings back the media together with its files.
     *
     * After a commit a failure to remove the files is reported, not thrown. Without a transaction the
     * files are removed right away and a failure is thrown, as when deleting a media otherwise.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function removingFilesAfterCommit(callable $callback): mixed
    {
        self::$removingFilesAfterCommit++;

        try {
            return $callback();
        } finally {
            self::$removingFilesAfterCommit--;
        }
    }

    /**
     * @internal Read by the media observer.
     */
    public function shouldRemoveFilesAfterCommit(): bool
    {
        return self::$removingFilesAfterCommit > 0;
    }

    /** @param  string  $collectionName */
    public function move(HasMedia $model, $collectionName = 'default', string $diskName = '', string $fileName = ''): self
    {
        // The media would be lost if the model is never saved.
        if (! $model->exists) {
            throw MediaCannotBeUpdated::cannotBeMovedToUnsavedModel($this);
        }

        $newMedia = $this->copy($model, $collectionName, $diskName, $fileName);

        $this->forceDelete();

        return $newMedia;
    }

    /**
     * @param  null|Closure(FileAdder): FileAdder  $fileAdderCallback
     */
    public function copy(
        HasMedia $model,
        string $collectionName = 'default',
        string $diskName = '',
        string $fileName = '',
        ?Closure $fileAdderCallback = null
    ): self {
        // The file adder removes the copy once it is added, which for an unsaved model happens
        // only when the model is created.
        $temporaryFile = TemporaryDirectory::createFile();

        try {
            /** @var Filesystem $filesystem */
            $filesystem = app(Filesystem::class);

            $filesystem->copyFromMediaLibrary($this, $temporaryFile);

            $fileAdder = $model
                ->addMedia($temporaryFile)
                ->withTemporaryFile()
                ->usingName($this->name)
                ->usingFileName($fileName !== '' ? $fileName : $this->file_name)
                ->setOrder($this->order_column)
                ->withManipulations($this->manipulations)
                ->withCustomProperties($this->custom_properties);

            if ($fileAdderCallback instanceof Closure) {
                $fileAdder = $fileAdderCallback($fileAdder);
            }

            return $fileAdder->toMediaCollection($collectionName, $diskName);
        } catch (Throwable $exception) {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }

            throw $exception;
        }
    }

    public function responsiveImages(string $conversionName = ''): RegisteredResponsiveImages
    {
        return new RegisteredResponsiveImages($this, $conversionName);
    }

    public function stream(string $conversion = '')
    {
        /** @var Filesystem $filesystem */
        $filesystem = app(Filesystem::class);

        if ($conversion === '') {
            return $filesystem->getStream($this);
        }

        if (! $this->hasGeneratedConversion($conversion)) {
            throw InvalidConversion::unknownName($conversion);
        }

        return $filesystem->getConversionStream($this, $conversion);
    }

    public function toHtml(): string
    {
        return $this->img()->toHtml();
    }

    public function img(string $conversionName = '', $extraAttributes = []): HtmlableMedia
    {
        return (new HtmlableMedia($this))
            ->conversion($conversionName)
            ->attributes($extraAttributes);
    }

    public function __invoke(...$arguments): HtmlableMedia
    {
        return $this->img(...$arguments);
    }

    public function mailAttachment(string $conversion = ''): Attachment
    {
        if ($conversion !== '') {
            // Named after the conversion file, with the mime type the conversions disk reports.
            return Attachment::fromStorageDisk($this->conversions_disk, $this->getPathRelativeToRoot($conversion));
        }

        $attachment = Attachment::fromStorageDisk($this->disk, $this->getPathRelativeToRoot())->as($this->file_name);

        if ($this->mime_type) {
            $attachment->withMime($this->mime_type);
        }

        return $attachment;
    }

    public function toMailAttachment(): Attachment
    {
        return $this->mailAttachment();
    }

    public function saveOrTouch(): bool
    {
        if (! $this->exists || $this->isDirty()) {
            return $this->save();
        }

        return $this->touch();
    }
}
