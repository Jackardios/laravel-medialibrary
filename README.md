<h1>Associate files with Eloquent models</h1>

[![run-tests](https://github.com/jackardios/laravel-medialibrary/actions/workflows/run-tests.yml/badge.svg)](https://github.com/jackardios/laravel-medialibrary/actions/workflows/run-tests.yml)
[![static-analysis](https://github.com/jackardios/laravel-medialibrary/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/jackardios/laravel-medialibrary/actions/workflows/static-analysis.yml)

`jackardios/laravel-medialibrary` is a fork of [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary). It follows upstream (2.x is based on spatie 11.23.8) and adds fixes and performance work on top. The API, the `Spatie\MediaLibrary` namespace and the configuration stay those of the original package, so existing code and the [upstream documentation](https://spatie.be/docs/laravel-medialibrary/v11) apply. Where the fork behaves differently, the `docs` directory of this repository says so.

This package can associate all sorts of files with Eloquent models. It provides a
simple API to work with:

```php
$newsItem = News::find(1);
$newsItem->addMedia($pathToFile)->toMediaCollection('images');
```

It can handle your uploads directly:

```php
$newsItem->addMedia($request->file('image'))->toMediaCollection('images');
```

Want to store some large files on another filesystem? No problem:

```php
$newsItem->addMedia($smallFile)->toMediaCollection('downloads', 'local');
$newsItem->addMedia($bigFile)->toMediaCollection('downloads', 's3');
```

The storage of the files is handled by [Laravel's Filesystem](https://laravel.com/docs/filesystem),
so you can use any filesystem you like. Additionally, the package can create image manipulations
on images and pdfs that have been added in the media library.

## Requirements and installation

| version | PHP | Laravel |
|---|---|---|
| 2.x | 8.3 – 8.5 | 12, 13 |
| 1.x | 8.2+ | 10, 11 |

```bash
composer require jackardios/laravel-medialibrary:^2.0
```

The fork uses the same namespace as `spatie/laravel-medialibrary` and declares a conflict with it; remove that package first. Setting up the package (publishing the migration and the config, preparing models) works as described in the [upstream installation guide](https://spatie.be/docs/laravel-medialibrary/v11/installation-setup).

## How the fork differs from spatie/laravel-medialibrary

- **Safer url downloads.** `addMediaFromUrl` refuses urls that resolve to private or reserved addresses (localhost, the local network, cloud metadata services), checks every redirect, connects to the address it checked and stops downloading at `max_file_size`. Internal hosts can be trusted in the config. See [using a custom media downloader](docs/advanced-usage/using-a-custom-media-downloader.md).
- **Safer file names.** Besides upstream's extension blocklist, `.user.ini` and `web.config` are refused, and the default sanitizer also replaces the characters Windows does not allow and renames reserved device names such as `CON`.
- **Separate conversions disk done right.** Urls, srcsets, regeneration, existence checks, renames, moves, mail attachments and deletion all use the conversions disk.
- **Responsive images kept consistent.** They are replaced only once a new set has been generated, renamed with the media, and removed exactly.
- **`media-library:regenerate`** behaves as upstream's by default and offers the fork's fast paths: `--queue-all` queues one job per media that downloads the original once for all conversions and responsive images, and `--trust-database` lets `--only-missing` read the `generated_conversions` column instead of the disk.
- **Faster url and html building.** A media keeps its conversion collection while its urls are built, conversions build their optimizers only to perform, and a srcset builds one url generator instead of one per image.
- Many smaller fixes; see the [changelog](CHANGELOG.md).

## Upgrading

Please see [UPGRADING](UPGRADING.md) for the steps from 1.x to 2.0.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed.

## Testing

```bash
composer test
```

The S3 tests run against any S3-compatible server when `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` and `AWS_BUCKET` are set, together with `AWS_ENDPOINT`, `AWS_URL` and `AWS_USE_PATH_STYLE_ENDPOINT=1` for MinIO. The [workflow](.github/workflows/run-tests.yml) shows how CI starts one.

## Security

If you discover a security issue, please report it [privately on GitHub](https://github.com/jackardios/laravel-medialibrary/security/advisories/new) instead of using the issue tracker.

## Credits

This package is a fork of [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary) by [Spatie](https://spatie.be).

- [Freek Van der Herten](https://github.com/freekmurze) and [all contributors of the original package](https://github.com/spatie/laravel-medialibrary/graphs/contributors)
- [Jackardios](https://github.com/jackardios), maintainer of the fork

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
