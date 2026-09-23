<?php

namespace Spatie\MediaLibrary\MediaCollections\Models;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ConversionCollection;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\HtmlableMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\CustomMediaProperties;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\HasUuid;
use Spatie\MediaLibrary\MediaCollections\Models\Concerns\IsSorted;
use Spatie\MediaLibrary\ResponsiveImages\RegisteredResponsiveImages;
use Spatie\MediaLibrary\Support\File;
use Spatie\MediaLibrary\Support\MediaLibraryPro;
use Spatie\MediaLibrary\Support\TemporaryDirectory;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\UrlGeneratorFactory;
use Spatie\MediaLibraryPro\Models\TemporaryUpload;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
 * @property-read string $humanReadableSize
 * @property-read string $preview_url
 * @property-read string $original_url
 * @property int $size
 * @property ?int $order_column
 * @property array $manipulations
 * @property array $custom_properties
 * @property array $generated_conversions
 * @property array $responsive_images
 * @property-read ?\Illuminate\Support\Carbon $created_at
 * @property-read ?\Illuminate\Support\Carbon $updated_at
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

    public function newCollection(array $models = []): MediaCollection
    {
        return new MediaCollection($models);
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

    public function getTemporaryUrl(DateTimeInterface $expiration, string $conversionName = '', array $options = []): string
    {
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

    public function getDiskDriverName(): string
    {
        return strtolower(config("filesystems.disks.{$this->disk}.driver"));
    }

    public function getConversionsDiskDriverName(): string
    {
        $diskName = $this->conversions_disk ?? $this->disk;

        return strtolower(config("filesystems.disks.{$diskName}.driver"));
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

    public function forgetCustomProperty(string $name): self
    {
        $customProperties = $this->custom_properties;

        Arr::forget($customProperties, $name);

        $this->custom_properties = $customProperties;

        return $this;
    }

    /**
     * The conversions registered for this media, built once per media instance.
     *
     * Building the collection instantiates the owner model and re-runs every
     * conversion registration, and URL generation needs it once per URL (each
     * conversion URL and every srcset entry), so serializing one image used to
     * rebuild it about ten times.
     *
     * The memo is keyed by the raw attributes: any attribute change (collection,
     * model type, manipulations, or anything else a registration callback may
     * read from the media) builds a fresh collection. Owners that register
     * conversions using the model instance are never memoized, because their
     * conversions may follow the owner's state. The returned collection is
     * shared, so callers must not mutate it or its conversions; processing paths
     * that do keep using {@see ConversionCollection::createForMedia()}.
     *
     * Before PHP 8.3 the cycle collector cannot free a WeakMap entry whose value
     * references its own key (the collection holds its media), so every memoized
     * media would live as long as the process. There the collection is simply
     * built on every call, as it was before the memo existed.
     */
    public function getConversionCollection(): ConversionCollection
    {
        if (PHP_VERSION_ID < 80300) {
            return ConversionCollection::createForMedia($this);
        }

        $memo = self::$conversionCollections ??= new WeakMap();
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

        return $conversions;
    }

    public function getMediaConversionNames(): array
    {
        $conversions = ConversionCollection::createForMedia($this);

        return $conversions->map(fn (Conversion $conversion) => $conversion->getName())->toArray();
    }

    public function getGeneratedConversions(): Collection
    {
        return collect($this->generated_conversions ?? []);
    }

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

    public function setStreamChunkSize(int $chunkSize): self
    {
        $this->streamChunkSize = $chunkSize;

        return $this;
    }

    public function toResponse($request): StreamedResponse
    {
        return $this->buildResponse($request, 'attachment');
    }

    public function toInlineResponse($request): StreamedResponse
    {
        return $this->buildResponse($request, 'inline');
    }

    private function buildResponse($request, string $contentDispositionType): StreamedResponse
    {
        $filename = str_replace('"', '\'', Str::ascii($this->getDownloadFilename()));

        $downloadHeaders = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type' => $this->mime_type,
            'Content-Length' => $this->size,
            'Content-Disposition' => $contentDispositionType.'; filename="'.$filename.'"',
            'Pragma' => 'public',
        ];

        return response()->stream(function () {
            $stream = $this->stream();

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
        return count($this->getResponsiveImageUrls($conversionName)) > 0;
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

    /** @param  string  $collectionName */
    public function move(HasMedia $model, $collectionName = 'default', string $diskName = '', string $fileName = ''): self
    {
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
        $temporaryDirectory = TemporaryDirectory::create();

        $temporaryFile = $temporaryDirectory->path('/').DIRECTORY_SEPARATOR.$this->file_name;

        /** @var Filesystem $filesystem */
        $filesystem = app(Filesystem::class);

        $filesystem->copyFromMediaLibrary($this, $temporaryFile);

        $fileAdder = $model
            ->addMedia($temporaryFile)
            ->usingName($this->name)
            ->setOrder($this->order_column)
            ->withManipulations($this->manipulations)
            ->withCustomProperties($this->custom_properties);

        if ($fileName !== '') {
            $fileAdder->usingFileName($fileName);
        }

        if ($fileAdderCallback instanceof Closure) {
            $fileAdder = $fileAdderCallback($fileAdder);
        }

        $newMedia = $fileAdder->toMediaCollection($collectionName, $diskName);

        $temporaryDirectory->delete();

        return $newMedia;
    }

    public function responsiveImages(string $conversionName = ''): RegisteredResponsiveImages
    {
        return new RegisteredResponsiveImages($this, $conversionName);
    }

    public function stream()
    {
        /** @var Filesystem $filesystem */
        $filesystem = app(Filesystem::class);

        return $filesystem->getStream($this);
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

    public function temporaryUpload(): BelongsTo
    {
        MediaLibraryPro::ensureInstalled();

        return $this->belongsTo(TemporaryUpload::class);
    }

    public static function findWithTemporaryUploadInCurrentSession(array $uuids): EloquentCollection
    {
        MediaLibraryPro::ensureInstalled();

        return static::query()
            ->whereIn('uuid', $uuids)
            ->whereHasMorph(
                'model',
                [TemporaryUpload::class],
                fn (Builder $builder) => $builder->where('session_id', session()->getId())
            )
            ->get();
    }

    public function mailAttachment(string $conversion = ''): Attachment
    {
        // A conversion is read from the `conversions_disk`, which may differ from the original's `disk`.
        $disk = $conversion === '' ? $this->disk : ($this->conversions_disk ?? $this->disk);

        $attachment = Attachment::fromStorageDisk($disk, $this->getPathRelativeToRoot($conversion))->as($this->file_name);

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
