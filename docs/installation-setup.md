---
title: Base installation
weight: 4
---

Media Library can be installed via Composer:

If you only use the base package issue this command:

```bash
composer require "jackardios/laravel-medialibrary"
```

Packages that require `spatie/laravel-medialibrary`, such as Media Library Pro, cannot be installed with this package.

## Preparing the database

You need to publish the migration to create the `media` table:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
```

After that, you need to run migrations.

```bash
php artisan migrate
```

## Publishing the config file

Publishing the config file is optional:

```bash
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
```

This is the default content of the config file:

```php
<?php

use Spatie\ImageOptimizer\Optimizers\Avifenc;
use Spatie\ImageOptimizer\Optimizers\Cwebp;
use Spatie\ImageOptimizer\Optimizers\Gifsicle;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Spatie\ImageOptimizer\Optimizers\Optipng;
use Spatie\ImageOptimizer\Optimizers\Pngquant;
use Spatie\ImageOptimizer\Optimizers\Svgo;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Avif;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Pdf;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Svg;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Video;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Webp;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\Conversions\Jobs\RegenerateMediaJob;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Spatie\MediaLibrary\ResponsiveImages\Jobs\GenerateResponsiveImagesJob;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;
use Spatie\MediaLibraryPro\Models\TemporaryUpload;

return [

    /*
     * The disk on which to store added files and derived images by default. Choose
     * one or more of the disks you've configured in config/filesystems.php.
     */
    'disk_name' => env('MEDIA_DISK', 'public'),

    /*
     * The disk on which to store conversions (thumbnails, etc.) and responsive images
     * when no disk is specified explicitly on the media collection or via
     * `storingConversionsOnDisk()`. When left null, conversions are stored on the
     * same disk as the original media — preserving previous behavior.
     *
     * This is useful when the originals live on a remote disk (e.g. S3) but the
     * generated derivatives should stay local for faster access and lower egress.
     */
    'conversions_disk_name' => env('MEDIA_CONVERSIONS_DISK', null),

    /*
     * The maximum file size of an item in bytes.
     * Adding a larger file will result in an exception.
     */
    'max_file_size' => 1024 * 1024 * 10, // 10MB

    /*
     * Uploads whose file name contains any of these extensions will be rejected.
     * The check looks at every extension in the file name, so a file named
     * `malicious.php.jpg` is blocked as well. Matching is case-insensitive
     * and a leading dot is optional.
     *
     * The default list lives on the `FileAdder` class so the shipped config
     * and the in-code fallback (used when the config is cached without the
     * key) cannot drift. Override here to extend or shrink it.
     */
    'disallowed_extensions' => FileAdder::$defaultDisallowedExtensions,

    /*
     * When this is set to an array of extensions, only uploads whose final
     * extension is in the list will be accepted. Matching is case-insensitive
     * and a leading dot is optional. The `disallowed_extensions` list above
     * is still enforced, so an interior dangerous segment (such as the `php`
     * in `shell.php.jpg`) is rejected even if the final extension is allowed.
     * Leave `null` to disable allowlisting.
     */
    'allowed_extensions' => null,

    /*
     * This queue connection will be used to generate derived and responsive images.
     * Leave empty to use the default queue connection.
     */
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),

    /*
     * This queue will be used to generate derived and responsive images.
     * Leave empty to use the default queue.
     */
    'queue_name' => env('MEDIA_QUEUE', ''),

    /*
     * By default all conversions will be performed on a queue.
     */
    'queue_conversions_by_default' => env('QUEUE_CONVERSIONS_BY_DEFAULT', true),

    /*
     * Should database transactions be run after database commits?
     */
    'queue_conversions_after_database_commit' => env('QUEUE_CONVERSIONS_AFTER_DB_COMMIT', true),

    /*
     * The fully qualified class name of the media model.
     */
    'media_model' => Media::class,

    /*
     * The fully qualified class name of the media observer.
     */
    'media_observer' => MediaObserver::class,

    /*
     * When enabled, media collections will be serialised using the default
     * laravel model serialization behaviour.
     *
     * Keep this option disabled if using Media Library Pro components (https://medialibrary.pro)
     */
    'use_default_collection_serialization' => false,

    /*
     * The fully qualified class name of the model used for temporary uploads.
     *
     * This model is only used in Media Library Pro (https://medialibrary.pro)
     */
    'temporary_upload_model' => TemporaryUpload::class,

    /*
     * When enabled, Media Library Pro will only process temporary uploads that were uploaded
     * in the same session. You can opt to disable this for stateless usage of
     * the pro components.
     */
    'enable_temporary_uploads_session_affinity' => true,

    /*
     * When enabled, Media Library pro will generate thumbnails for uploaded file.
     */
    'generate_thumbnails_for_temporary_uploads' => true,

    /*
     * This is the class that is responsible for naming generated files.
     */
    'file_namer' => DefaultFileNamer::class,

    /*
     * The class that contains the strategy for determining a media file's path.
     */
    'path_generator' => DefaultPathGenerator::class,

    /*
     * The class that contains the strategy for determining how to remove files.
     */
    'file_remover_class' => DefaultFileRemover::class,

    /*
     * Here you can specify which path generator should be used for the given class.
     */
    'custom_path_generators' => [
        // Model::class => PathGenerator::class
        // or
        // 'model_morph_alias' => PathGenerator::class
    ],

    /*
     * When urls to files get generated, this class will be called. Use the default
     * if your files are stored locally above the site root or on s3.
     */
    'url_generator' => DefaultUrlGenerator::class,

    /*
     * Moves media on updating to keep path consistent. Enable it only with a custom
     * PathGenerator that uses, for example, the media UUID.
     */
    'moves_media_on_update' => false,

    /*
     * Whether to activate versioning when urls to files get generated.
     * When activated, this attaches a ?v=xx query string to the URL.
     */
    'version_urls' => false,

    /*
     * The media library will try to optimize all converted images by removing
     * metadata and applying a little bit of compression. These are
     * the optimizers that will be used by default.
     */
    'image_optimizers' => [
        Jpegoptim::class => [
            '-m85', // set maximum quality to 85%
            '--force', // ensure that progressive generation is always done also if a little bigger
            '--strip-all', // this strips out all text information such as comments and EXIF data
            '--all-progressive', // this will make sure the resulting image is a progressive one
        ],
        Pngquant::class => [
            '--force', // required parameter for this package
        ],
        Optipng::class => [
            '-i0', // this will result in a non-interlaced, progressive scanned image
            '-o2', // this set the optimization level to two (multiple IDAT compression trials)
            '-quiet', // required parameter for this package
        ],
        Svgo::class => [
            '--disable=cleanupIDs', // disabling because it is known to cause troubles
        ],
        Gifsicle::class => [
            '-b', // required parameter for this package
            '-O3', // this produces the slowest but best results
        ],
        Cwebp::class => [
            '-m 6', // for the slowest compression method in order to get the best compression.
            '-pass 10', // for maximizing the amount of analysis pass.
            '-mt', // multithreading for some speed improvements.
            '-q 90', // quality factor that brings the least noticeable changes.
        ],
        Avifenc::class => [
            '-a cq-level=23', // constant quality level, lower values mean better quality and greater file size (0-63).
            '-j all', // number of jobs (worker threads, "all" uses all available cores).
            '--min 0', // min quantizer for color (0-63).
            '--max 63', // max quantizer for color (0-63).
            '--minalpha 0', // min quantizer for alpha (0-63).
            '--maxalpha 63', // max quantizer for alpha (0-63).
            '-a end-usage=q', // rate control mode set to Constant Quality mode.
            '-a tune=ssim', // SSIM as tune the encoder for distortion metric.
        ],
    ],

    /*
     * These generators will be used to create an image of media files.
     */
    'image_generators' => [
        Image::class,
        Webp::class,
        Avif::class,
        Pdf::class,
        Svg::class,
        Video::class,
    ],

    /*
     * The path where to store temporary files while performing image conversions.
     * If set to null, storage_path('media-library/temp') will be used.
     */
    'temporary_directory_path' => null,

    /*
     * The engine that should perform the image conversions.
     * Should be either `gd`, `imagick` or `vips`.
     */
    'image_driver' => env('IMAGE_DRIVER', 'gd'),

    /*
     * FFMPEG & FFProbe binaries paths, only used if you try to generate video
     * thumbnails and have installed the php-ffmpeg/php-ffmpeg composer
     * dependency.
     */
    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),

    /*
     * The timeout (in seconds) that will be used when generating video
     * thumbnails via FFMPEG.
     */
    'ffmpeg_timeout' => env('FFMPEG_TIMEOUT', 900),

    /*
     * The number of threads that FFMPEG should use. 0 means that FFMPEG
     * may decide itself.
     */
    'ffmpeg_threads' => env('FFMPEG_THREADS', 0),

    /*
     * Here you can override the class names of the jobs used by this package. Make sure
     * your custom jobs extend the ones provided by the package.
     */
    'jobs' => [
        'perform_conversions' => PerformConversionsJob::class,
        'generate_responsive_images' => GenerateResponsiveImagesJob::class,
        'regenerate_media' => RegenerateMediaJob::class,
    ],

    /*
     * When using the addMediaFromUrl method you may want to replace the default downloader.
     * This is particularly useful when the url of the image is behind a firewall and
     * need to add additional flags, possibly using curl.
     */
    'media_downloader' => DefaultDownloader::class,

    /*
     * When using the addMediaFromUrl method the SSL is verified by default.
     * This is option disables SSL verification when downloading remote media.
     * Please note that this is a security risk and should only be false in a local environment.
     */
    'media_downloader_ssl' => env('MEDIA_DOWNLOADER_SSL', true),

    /*
     * When using the addMediaFromUrl method, urls whose host resolves to a private or
     * reserved address (localhost, the local network, cloud metadata services) are
     * refused, redirects included. The downloader connects to the address it checked.
     * Only disable this when every url comes from a trusted source.
     */
    'media_downloader_blocks_private_networks' => env('MEDIA_DOWNLOADER_BLOCKS_PRIVATE_NETWORKS', true),

    /*
     * Hosts that may be downloaded from even when they resolve to a private address,
     * for example an internal file server. Wildcards are allowed: '*.internal.example'.
     */
    'media_downloader_trusted_hosts' => [],

    /*
     * The default lifetime in minutes for temporary urls.
     * This is used when you call the `getLastTemporaryUrl` or `getLastTemporaryUrl` method on a media item.
     */
    'temporary_url_default_lifetime' => env('MEDIA_TEMPORARY_URL_DEFAULT_LIFETIME', 5),

    'remote' => [
        /*
         * Any extra headers that should be included when uploading media to
         * a remote disk. Even though supported headers may vary between
         * different drivers, a sensible default has been provided.
         *
         * Supported by S3: CacheControl, Expires, StorageClass,
         * ServerSideEncryption, Metadata, ACL, ContentEncoding
         */
        'extra_headers' => [
            'CacheControl' => 'max-age=604800',
        ],
    ],

    'responsive_images' => [
        /*
         * This class is responsible for calculating the target widths of the responsive
         * images. By default we optimize for filesize and create variations that each are 30%
         * smaller than the previous one. More info in the documentation.
         *
         * https://docs.spatie.be/laravel-medialibrary/v9/advanced-usage/generating-responsive-images
         */
        'width_calculator' => FileSizeOptimizedWidthCalculator::class,

        /*
         * By default rendering media to a responsive image will add some javascript and a tiny placeholder.
         * This ensures that the browser can already determine the correct layout.
         * When disabled, no tiny placeholder is generated.
         */
        'use_tiny_placeholders' => true,

        /*
         * This class will generate the tiny placeholder used for progressive image loading. By default
         * the media library will use a tiny blurred jpg image.
         */
        'tiny_placeholder_generator' => Blurred::class,
    ],

    /*
     * When enabling this option, a route will be registered that will enable
     * the Media Library Pro Vue and React components to move uploaded files
     * in a S3 bucket to their right place.
     */
    'enable_vapor_uploads' => env('ENABLE_MEDIA_LIBRARY_VAPOR_UPLOADS', false),

    /*
     * When converting Media instances to response the media library will add
     * a `loading` attribute to the `img` tag. Here you can set the default
     * value of that attribute.
     *
     * Possible values: 'lazy', 'eager', 'auto' or null if you don't want to set any loading instruction.
     *
     * More info: https://css-tricks.com/native-lazy-loading/
     */
    'default_loading_attribute_value' => null,

    /*
     * You can specify a prefix for that is used for storing all media.
     * If you set this to `/my-subdir`, all your media will be stored in a `/my-subdir` directory.
     */
    'prefix' => env('MEDIA_PREFIX', ''),

    /*
     * When forcing lazy loading, media will be loaded even if you don't eager load media and you have
     * disabled lazy loading globally in the service provider.
     */
    'force_lazy_loading' => env('FORCE_MEDIA_LIBRARY_LAZY_LOADING', true),
];
```

## Adding a media disk

By default, the media library will store its files on Laravel's `public` disk. If you want a dedicated disk you should add a disk to `config/filesystems.php`. This would be a typical configuration:

```php
    ...
    'disks' => [
        ...

        'media' => [
            'driver' => 'local',
            'root'   => public_path('media'),
            'url'    => env('APP_URL').'/media',
            'visibility' => 'public',
            'throw' => false,
        ],
    ...
```

Don't forget to `.gitignore` the directory of your configured disk, so the files won't end up in your git repo.

To store all media on that disk by default, you should set the `disk_name` config value in the `media-library` config file to the name of the disk you added.

```php
// config/media-library.php

return [
    'disk_name' => 'media',

    // ...
];
```

Want to use S3? Then follow Laravel's instructions on [how to add the S3 Flysystem driver](https://laravel.com/docs/filesystem#configuration). If possible, we recommend [using a remote filesystem like S3](https://twitter.com/taylorotwell/status/1153326292412129280) instead of your local filesystem to prevent security issues.

If you keep your originals on a remote disk like S3 but want to store the generated conversions (thumbnails, responsive images) on a different disk — for example, locally so the application can serve them without an extra remote round-trip — you can set the `conversions_disk_name` config value:

```php
// config/media-library.php

return [
    'disk_name' => 's3',
    'conversions_disk_name' => 'public',

    // ...
];
```

When `conversions_disk_name` is left `null` (the default), conversions are stored on the same disk as the original media. The setting is overridden by any explicit `->storingConversionsOnDisk(...)` call or by a `storeConversionsOnDisk(...)` setting on a media collection.

## Setting up a queue

If you are planning on working with image manipulations it's recommended to configure a queue on your server and specify it in the config file.

### Setting up optimization tools

Media library will use these tools to [optimize converted images](converting-images/optimizing-converted-images.md) if they are present on your system:

- [JpegOptim](http://freecode.com/projects/jpegoptim)
- [Optipng](http://optipng.sourceforge.net/)
- [Pngquant 2](https://pngquant.org/)
- [SVGO](https://github.com/svg/svgo)
- [Gifsicle](http://www.lcdf.org/gifsicle/)
- [Avifenc](https://github.com/AOMediaCodec/libavif/blob/main/doc/avifenc.1.md)

Here's how to install all the optimizers on Ubuntu:

```bash
sudo apt install jpegoptim optipng pngquant gifsicle libavif-bin
npm install -g svgo
```

If you don't want to install `npm` on your Ubuntu server, you can use `snap` which is installed by default:

```bash
sudo apt install jpegoptim optipng pngquant gifsicle libavif-bin
sudo snap install svgo
```

Here's how to install all the optimizers on Alpine Linux:

```bash
apk add jpegoptim optipng pngquant gifsicle libavif-apps
npm install -g svgo
```

Here's how to install the binaries on MacOS (using [Homebrew](https://brew.sh/)):

```bash
brew install jpegoptim
brew install optipng
brew install pngquant
brew install svgo
brew install gifsicle
brew install libavif
```
