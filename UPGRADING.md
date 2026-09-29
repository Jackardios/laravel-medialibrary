# Upgrading

Because there are many breaking changes an upgrade is not that easy. There are many edge cases this guide does not cover. We accept PRs to improve this guide.

## From jackardios/laravel-medialibrary 1.x to 2.0

2.0 brings the fork up to spatie/laravel-medialibrary 11.23.8, so it also contains every upstream change since 11.7.3. The [changelog](CHANGELOG.md) lists them.

### Requirements

- PHP 8.3 or higher and Laravel 12 or 13. Stay on 1.x for Laravel 10/11 or PHP 8.2.
- Update the constraint: `composer require jackardios/laravel-medialibrary:^2.0`. The package conflicts with `spatie/laravel-medialibrary`; remove that one if it is still installed. Packages that require `spatie/laravel-medialibrary` (Media Library Pro, the Filament plugin) cannot be installed with the fork.

### Database and config

- No migration is needed.
- New config keys are merged from the package's config automatically. If you published the config, compare it with `config/media-library.php` of the package: `media_downloader_blocks_private_networks`, `media_downloader_trusted_hosts`, `conversions_disk_name`, `queue_conversions_after_database_commit` (default `true`), `disallowed_extensions` / `allowed_extensions`, `media_observer`, `temporary_url_default_lifetime`, `ffmpeg_timeout`, `ffmpeg_threads`.

### Adding media

- **Urls on private networks.** `addMediaFromUrl` throws `InvalidUrl` for a url whose host, or a host it redirects to, resolves to a private or reserved address, and for a host written with non-ascii characters or percent-encoding (write it in punycode, `xn--...`). If you download from internal hosts, list them in `media_downloader_trusted_hosts` (wildcards allowed), or set `MEDIA_DOWNLOADER_BLOCKS_PRIVATE_NETWORKS=false` when every url is trusted. A download bigger than `max_file_size` now stops with `FileIsTooBig` while downloading. The `HttpFacadeDownloader` downloads a checked url without a proxy (it ignores `HTTP_PROXY` and the like); list a host in `media_downloader_trusted_hosts` if it can only be reached through your proxy. Tests using `Http::fake()` with the `HttpFacadeDownloader` are not affected. A custom downloader has to protect itself; it can use `Spatie\MediaLibrary\Downloaders\UrlGuard`.
- **Refused file names.** File names with a dangerous segment (`.php`, `.phtml`, `.phar`, `.htaccess`, `.cgi`, `.asp`, `.jsp`, ... anywhere in the name), `.user.ini` and `web.config` throw `FileNameNotAllowed`, also when your own sanitizer (`sanitizingFileName()`) or file namer made the name, and when a media is renamed (`$media->file_name = ...`). A name that leaves its directory (`..`, a leading `/`, a backslash) or names a Windows drive or data stream (`:`) is refused the same way, and the checks ignore the dots and spaces Windows drops from the end of a name (`shell.php.`); `other/file.jpg` is still allowed. Validate uploads in your application to answer with a validation error instead of a server error. `allowed_extensions` / `disallowed_extensions` in the config adjust the list.
- **Sanitized file names.** The default sanitizer also replaces `: * ? " < > |` with `-`, strips trailing dots and spaces and prefixes reserved Windows names (`CON`, `NUL`, `COM1`, ...) with `_`. New media may therefore get different file names than before; existing media keep theirs. Use `sanitizingFileName()` to keep your own rules; the blocklist still applies to its result.
- Files from urls without an extension get the usual extension of their type (`jpg`, not `jpeg`).
- `updateMedia()` throws `MediaCannotBeUpdated` when an item belongs to another model.

### Regenerating

- `media-library:regenerate --only-missing` checks the conversions disk again, as upstream does. To keep 1.x's faster behaviour, which trusted the `generated_conversions` column, add `--trust-database`.
- Remove `--verify-existence` from your scripts: it no longer exists, checking the disk is the default.
- With an asynchronous queue connection, 1.x queued one job per media for everything. 2.0 runs non-queued conversions in the command's process and queues the queued ones, as upstream does. Add `--queue-all` (or `--queue-connection=...`) to get one `RegenerateMediaJob` per media again.
- `--with-responsive-images` regenerates the responsive images of the original only for media that has them.
- `FileManipulator::regenerateDerivedFiles()` and `RegenerateMediaJob` keep their signatures; with `$verifyExistence = true` the disk decides what is missing.

### Behaviour you may rely on

- `toResponse()` / `toInlineResponse()` of a missing file throw `FileDoesNotExist` before sending; `Media::copy()` and `copyFromMediaLibrary()` throw `FileDoesNotExist` when the original is missing.
- A conversion is sent and attached under its own file name and mime type.
- Renaming a media throws `MediaCannotBeUpdated` when its file cannot be moved.
- `Media::move()` to a model that is not saved yet throws `MediaCannotBeUpdated`. Save the model first.
- The values of an image tag's extra attributes are escaped. Pass plain values, not HTML.
- Urls of disks with a configured `url` percent-encode the file name. Urls of media without `updated_at` have no `?v=` version.
- `ResponsiveImagesGeneratedEvent` and `ConversionHasBeenCompletedEvent` fire once the result is saved.
- The responsive images job, like the conversion jobs, is queued after the database commit when `queue_conversions_after_database_commit` is on.
- The `MediaRepository` getters return a `LazyCollection` (upstream 11.7.6).
- Deleting media removes the files of the conversions that are registered; files of conversions you removed from your code stay on the disk.
- `media-library:clean` also removes unknown directories on every disk the media table uses. Check its dry run (`--dry-run`) before running it on a disk that holds other files.
- A conversion collection is kept for the last 16 media it was built for, while their attributes are unchanged. If you change config (such as `file_namer`) at runtime, use fresh media instances.

### Extending the package

- `RegenerateCommand::handle()` returns `int` (the exit status). A subclass that overrides it must declare `: int`.
- `Conversion` resolves its file namer when it first names a file, and builds its optimizer chain when its manipulations are read. A subclass that reads the protected `$fileNamer` directly has to call `getConversionFile()` first.
- A class that overrides one of these methods, or implements `HasMedia` without the trait, must match the new signatures (most come from upstream):
  - `HasMedia::getMediaCollection(string $collectionName = 'default'): ?MediaCollection` is new in the interface.
  - `Media::toResponse($request, string $conversion = '')`, `toInlineResponse($request, string $conversion = '')`, `stream(string $conversion = '')` and `getTemporaryUrl(?DateTimeInterface $expiration = null, ...)`.
  - `InteractsWithMedia::hasMedia(string $collectionName = 'default', array|callable $filters = [])` and `getFirstTemporaryUrl(?DateTimeInterface $expiration = null, ...)`.
  - `Conversion::withResponsiveImages(bool $withResponsiveImages = true)`.
  - `FileManipulator::createDerivedFiles(..., bool $queueAll = false)`.
  - `MediaStream::getZipStream(bool $finish = true)`.
  - `ClearCommand::getMediaItems()`, `CleanCommand::getMediaItems()` and `CleanCommand::getOrphanedMediaItems()` return a `LazyCollection`.

### Removed

- `ResponsiveImageGenerator::cleanResponsiveImages()` (protected). Responsive images are replaced once the new set has been generated.

## From spatie/laravel-medialibrary 11.x to jackardios/laravel-medialibrary 2.0

- Replace the package: `composer remove spatie/laravel-medialibrary` and `composer require jackardios/laravel-medialibrary:^2.0`. The namespace stays `Spatie\MediaLibrary`, so no code changes are needed for that.
- Upgrade to spatie 11.23.8 first if you are on an older 11.x; the steps of the upstream releases apply.
- 2.0 behaves like spatie 11.23.8 except for what the 2.0.0 and 1.0.0 entries of the [changelog](CHANGELOG.md) list. The sections above on adding media (urls on private networks, refused and sanitized file names) apply to you as well.
- `media-library:regenerate` behaves as upstream's unless you add the new `--trust-database` or `--queue-all`. Upstream's `--queue-all` dispatched two jobs per media, one for its conversions and one for its responsive images; the fork's dispatches one `RegenerateMediaJob` per media, which downloads the original once, on the connection of `--queue-connection` or `queue_connection_name`.
- A class that overrides one of these methods must match the fork's signature:
  - `RegenerateCommand::handle(...): int` (upstream `void`).
  - `Media::saveOrTouch()` is public (upstream protected).
  - `Media::markAsConversionGenerated(string $conversionName, bool $persist = true)`.
  - `Filesystem::removeFile(Media $media, string $path, ?string $disk = null)`.
  - `ResponsiveImageGenerator::generateResponsiveImages(Media $media, ?string $baseImage = null)`.
  - `ResponsiveImageGenerator::cleanResponsiveImages()` (protected) is removed.

## Upgrading spatie/laravel-medialibrary

The sections below come from the original package.

### From v10 to v11

- Image v3 is now used. Make sure to update your image conversions to the new syntax. See [the image docs](https://spatie.be/docs/image/v3) for more info.
- All event names have gained the `Event` suffix. For example `Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAdded` is now `Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent`.


### From v9 to v10

Upgrading from v9 to v10 is straightforward. The biggest change is that we dropped support for PHP 7, and are using PHP 8 features.

### From v8 to v9

- add a `json` column `generated_conversions` to the `media` table (take a look at the default migration for the exact definition). You should copy the values you now have in the `generated_conversions` key of the `custom_properties` column to `generated_conversions`
- You can create this migration by running `php artisan make:migration AddGeneratedConversionsToMediaTable`.
- Here is the content that should be in the migration file
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AddGeneratedConversionsToMediaTable extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        if ( ! Schema::hasColumn( 'media', 'generated_conversions' ) ) {
            Schema::table( 'media', function ( Blueprint $table ) {
                $table->json( 'generated_conversions' )->nullable();
            } );
        }
        
        Media::query()
            ->where(function ($query) {
                $query->whereNull('generated_conversions')
                    ->orWhere('generated_conversions', '')
                    ->orWhereRaw("JSON_TYPE(generated_conversions) = 'NULL'");
            })
            ->whereRaw("JSON_LENGTH(custom_properties) > 0")
            ->update([
                'generated_conversions' => DB::raw("JSON_EXTRACT(custom_properties, '$.generated_conversions')"),
                // OPTIONAL: Remove the generated conversions from the custom_properties field as well:
                // 'custom_properties'     => DB::raw("JSON_REMOVE(custom_properties, '$.generated_conversions')")
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        /* Restore the 'generated_conversions' field in the 'custom_properties' column if you removed them in this migration
        Media::query()
                ->whereRaw("JSON_TYPE(generated_conversions) != 'NULL'")
                ->update([
                    'custom_properties' => DB::raw("JSON_SET(custom_properties, '$.generated_conversions', generated_conversions)")
                ]);
        */
    
        Schema::table( 'media', function ( Blueprint $table ) {
            $table->dropColumn( 'generated_conversions' );
        } );
    }
}
```

- rename `conversion_file_namer` key in the `media-library` config to `file_namer`. This will support both the conversions and responsive images from now on. More info [in our docs](https://spatie.be/docs/laravel-medialibrary/v9/advanced-usage/naming-generated-files).
- You will also need to change the value of this configuration key as the previous class was removed, the new default value is `Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer::class`
- in several releases of v8 config options were added. We recommend going over your config file in `config/media-library.php` and add any options that are present in the default config file that ships with this package.
- Media collection serialization has changed to support the newly introduced Media Library Pro components. If you are returning media collections directly from your controllers or serializing them to json manually then you can retain existing behaviour by setting `use_default_collection_serialization` to `true` inside `config/media-library.php`

### From v7 to v8

- internally the media library has been restructured and nearly all namespaces have changed. Class names remained the same. In your application code hunt to any usages of classes that start with `Spatie\MediaLibrary`. Take a look in the source code of medialibrary what the new namespace of the class is and use that. 
- rename `config/medialibrary.php` to `config/media-library.php`
- update in `config/media-library.php` the `media_model` to `Spatie\MediaLibrary\MediaCollections\Models\Media::class`

- all medialibrary commands have been renamed from `medialibrary:xxx` to `media-library:xxx`. Make sure to update all media library commands in your console kernel.
- the `Spatie\MediaLibrary\HasMedia\HasMediaTrait` has been renamed to `Spatie\MediaLibrary\InteractsWithMedia`. Make sure to update this in all models that use media. Also make sure that they implement the `HasMedia` interface, see [Preparing your model](https://spatie.be/docs/laravel-medialibrary/v8/basic-usage/preparing-your-model).
- Add a `conversions_disk` field to the `media` table ( varchar 255 nullable; you'll find the definition in the migrations file of the package) and for each row copy the value of `disk` to `conversions_disk`.
- Add a `uuid` field to the `media` table ( char 36 nullable) and fill each row with a unique value, preferably a `uuid`

You can use this snippet (in e.g. tinker) to fill the `uuid` field:

```php
use Spatie\MediaLibrary\MediaCollections\Models\Media;
Media::cursor()->each(
   fn (Media $media) => $media->update(['uuid' => Str::uuid()])
);
```

- Url generation has been vastly simplified. You should set the `url_generator` in the `media-library` config file to `Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator::class`. It will be able to handle most disks.
- remove the `s3.domain` key from the `media-library` config file
- spatie/pdf-to-image is now a suggestion dependency. Make sure to install it, if you want to create thumbnails for PDFs or SVGs
- `registerMediaConversions` and `registerMediaCollections` should now use the  `void` return type.
- if the `path_generator` key in the `media-library` config file was set to `null`, change the value to `Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator::class`
- if the `url_generator` key in the `media-library` config file was set to `null`, change the value to `Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator::class`
- the `rawUrlEncodeFilename` method on `BaseUrlGenerator` has been removed. Remove all calls in your own code to this method.
- `getConversionFile` on `Conversion` now accepts a `Media` instance instead of a `string`. In normal circumstance you wouldn't have used this function directly.
- the default collection name for responsive images was changed from `medialibrary_original` to `media_library_original` which requires you to update the `responsive_images` column and rename all generated files with that collection name. This is an example migration of how to do that (**read through the code and make sure it does what you want**):
```php
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Migrations\Migration;
use App\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

class RenameResponsiveImagesCollectionNameInMedia extends Migration
{

    const OLD_COLLECTION_NAME = 'medialibrary_original';
    const NEW_COLLECTION_NAME = 'media_library_original';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->change(self::OLD_COLLECTION_NAME, self::NEW_COLLECTION_NAME);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->change(self::NEW_COLLECTION_NAME, self::OLD_COLLECTION_NAME);
    }

    public function change(string $from, string $to)
    {
        /** @var Factory $filesystem */
        $filesystem = app(Factory::class);

        $pathGenerator = PathGeneratorFactory::create();

        // Find media with the old collection name is present
        Media::query()
            ->withoutGlobalScopes()
            ->whereNotNull('responsive_images->' . $from)
            ->cursor()
            ->each(function($media) use ($from, $to, $filesystem, $pathGenerator) {
                // Change the old collection key
                $responsive_images = array_merge(
                    $media->responsive_images,
                    [
                        $to => $media->responsive_images[$from],
                        $from => null
                    ]
                );
                // Remove it completely
                unset($responsive_images[$from]);

                // Responsive image path for this media
                $directory = $pathGenerator->getPathForResponsiveImages($media);
                // Media disk
                $disk = $filesystem->disk($media->disk);

                foreach($responsive_images[$to]['urls'] as &$filename) {
                    // Replace the old collection name with the new one
                    $newFilename = str_replace(
                        $from,
                        $to,
                        $filename
                    );
                    // If the old file exists move it on disk
                    if($disk->exists($directory . $filename)) {
                        $disk->move($directory . $filename, $directory . $newFilename);
                        // Update the new array by ref
                        $filename = $newFilename;
                    }
                }
                // Save the new array
                $media->responsive_images = $responsive_images;
                $media->save();
            });
    }
}
```

### 7.3.0

- Before `hasGeneratedConversion` will work, the custom properties 
of every media item will have to be re-written in the database, or all conversions must be regenerated.
This won't break any existing code, but in order to use the new feature, you will need to do a manual update of your media items.

### 7.1.0

- The `Filesystem` interface is removed, and the `DefaultFilesystem` implementation is renamed to `Filesystem`.
If you want your own filesystem implementation, you should extend the `Filesystem` class.
- The method `Filesystem::renameFile(Media $media, string $oldFileName)` was renamed to `Filesystem::syncFileNames(Media $media)`. If you're using your own implementation of `Filesystem`, please update the method signature.
- The `default_filesystem` config key has been changed to `disk_name`.
- The `custom_url_generator_class` and `custom_path_generator_class` config keys have been changed to `url_generator` and `path_generator`. (commit ba46d8008d26542c9a5ef0e39f779de801cd4f8f)

### From v6 to v7

- add the `responsive_images` column in the media table: `$table->json('responsive_images');`
- rename the `use Spatie\MediaLibrary\HasMedia\Interfaces\HasMedia;` interface to `use Spatie\MediaLibrary\HasMedia\HasMedia;`
- rename the `use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;` interface to `use Spatie\MediaLibrary\HasMedia\HasMedia;` as well (the distinction was [removed](https://github.com/spatie/laravel-medialibrary/commit/48f371a7b10cc82bbee5b781ab8784acc5ad0fc3#diff-f12df6f7f30b5ee54d9ccc6e56e8f93e)).
- all converted files should now start with the name of the original file. One way to achieve this is to navigate to your storage/media folder and run `find -type d -name "conversions" -exec rm -rf {} \;` (bash) to remove all existing converted files and then run `php artisan medialibrary:regenerate` to automatically recreate them with the proper file names. 
- `Spatie\MediaLibrary\Media` has been moved to `Spatie\MediaLibrary\Models\Media`. Update the namespace import of `Media` across your app
- The method definitions of `Spatie\MediaLibrary\Filesystem\Filesystem::add` and `Spatie\MediaLibrary\Filesystem\Filesystem::copyToMediaLibrary` are changed, they now use nullable string typehints for `$targetFileName` and `$type`.

### From v5 to v6

- the signature of `registerMediaConversions` has been changed.

Change every instance of

  ```php
  public function registerMediaConversions()
  ```
to

 ```php
 public function registerMediaConversions(Media $media = null)
 ```

 - change the `defaultFilesystem` key in the config file to `default_filesystem`
 - add the `image_optimizers` key from the default config file to your config file.
 - be aware that the media library will now optimize all conversions by default. If you do not want this tack on `nonOptimized` to all your media conversions.
 - `toMediaLibrary` has been removed. Use `toMediaCollection` instead.
 - `toMediaLibraryOnCloudDisk` has been removed. Use `toMediaCollectionOnCloudDisk` instead.


### From v4 to v5
- rename `config/laravel-medialibrary` to `config/medialibrary.php`. Some keys have been added or renamed. Please compare your config file against the one provided by this package
- all calls to `toCollection` and `toCollectionOnDisk` and `toMediaLibraryOnDisk` should be renamed to `toMediaLibrary`
- media conversions are now handled by `spatie/image`. Convert all manipulations on your conversion to manipulations supported by `spatie/image`.
- add a `mime_type` column to the `media` table, manually populate the column with the right values.
- calls to `getNestedCustomProperty`, `setNestedCustomProperty`, `forgetNestedCustomProperty` and `hasNestedCustomProperty` should be replaced by their non-nested counterparts.
- All exceptions have been renamed. If you were catching media library specific exception please look up the new name in /src/Exceptions.
- be aware`getMedia` and related functions now return only the media from the `default` collection
- `image_generators` have now been added to the config file.


### From v3 to v4
- All exceptions have been renamed. If you were catching media library specific exception please look up the new name in /src/Exceptions.
- Glide has been upgraded from 0.3 in 1.0. Glide renamed some operations in their 1.0 release, most notably the `crop` and `fit` ones. If you were using those in your conversions refer the Glide documentation how they should be changed.

### From v2 to v3
You can upgrade from v2 to v3 by performing these renames in your model that has media.

- `Spatie\MediaLibrary\HasMediaTrait` has been renamed to `Spatie\MediaLibrary\HasMedia\HasMediaTrait`.
- `Spatie\MediaLibrary\HasMedia` has been renamed to `Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions`
- `Spatie\MediaLibrary\HasMediaWithoutConversions` has been renamed to `Spatie\MediaLibrary\HasMedia\Interfaces\HasMedia`

In the config file you should rename the `filesystem`-option to `default_filesystem`.

In the db the `temp`-column must be removed. Add these columns:
- disk (varchar, 255)
- custom_properties (text)
You should set the value of disk column in all rows to the name the default_filesystem specified in the config file.

Note that this behaviour has changed:
- when calling `getMedia()` without providing a collection name all media will be returned (whereas previously only media
from the default collection would be returned)
- calling `hasMedia()` without a collection name returns true if any given collection contains files (wheres previously
it would only return try if files were present in the default collection)
- the `addMedia`-function has been replaced by a fluent interface.

### From v1 to v2
Because v2 is a complete rewrite a simple upgrade path is not available.
If you want to upgrade completely remove the v1 package and follow install instructions of v2.
