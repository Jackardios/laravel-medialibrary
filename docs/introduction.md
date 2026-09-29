---
title: Introduction
weight: 1
---

This package can associate all sorts of files with Eloquent models. It provides a simple, fluent API to work with.

Here are some quick code examples:

```php
$yourModel = YourModel::find(1);
$yourModel->addMedia($pathToFile)->toMediaCollection('images');
```

It can also directly handle your uploads:

```php
$yourModel->addMediaFromRequest('image')->toMediaCollection('images');
```

Want to store some large files on another filesystem? No problem:

```php
$yourModel->addMedia($smallFile)->toMediaCollection('downloads', 'local');
$yourModel->addMedia($bigFile)->toMediaCollection('downloads', 's3');
```

The storage of the files is handled by [Laravel's Filesystem](https://laravel.com/docs/12.x/filesystem), so you can plug in any compatible filesystem.

The package can also generate derived images such as thumbnails for images, videos and PDFs. Once you've [set up your model](basic-usage/preparing-your-model.md), they're easily accessible:

```php
$yourModel->getMedia('images')->first()->getUrl('thumb');
```
