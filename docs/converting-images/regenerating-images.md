---
title: Regenerating images
weight: 4
---

When you change a conversion on your model, all images that were previously generated will not be updated automatically. You can regenerate your images via an artisan command. Note that conversions are often queued, so it might take a while to see the effects of the regeneration in your application.

```bash
php artisan media-library:regenerate
```

If you only want to regenerate the images for a single model, you can specify it as a parameter:

```bash
php artisan media-library:regenerate "App\Models\Post"
```

When using a morph map, you should use the name of the morph.

```bash
php artisan media-library:regenerate "post"
```

If you only want to regenerate images for a few specific media items, you can pass their IDs using the `--ids` option:

```bash
php artisan media-library:regenerate --ids=1 --ids=2 --ids=3
```

A comma separated list of id's works too.

```bash
php artisan media-library:regenerate --ids=1,2,3
```

If you only want to regenerate images for one or many specific conversions, you can use the `--only` option:

```bash
php artisan media-library:regenerate --only=thumb --only=foo
```

If you only want to regenerate missing images, you can use the `--only-missing` option:

```bash
php artisan media-library:regenerate --only-missing
```

A conversion counts as missing when its file is not on the conversions disk. On large libraries on a remote disk such as S3 that means one request per conversion. Add `--trust-database` to decide from the `generated_conversions` column of the media record instead, without touching the filesystem. Files deleted out-of-band are then not noticed, so only use it when the column is reliable:

```bash
php artisan media-library:regenerate --only-missing --trust-database
```

If you want to force responsive images to be regenerated, you can use the `--with-responsive-images` option:

```bash
php artisan media-library:regenerate --with-responsive-images
```

## Queueing the regeneration

By default the command regenerates the way conversions are created when media is added: non-queued conversions run in the command's own process, queued conversions are dispatched to the `media-library.queue_connection_name` connection.

With `--queue-all`, the command dispatches **one job per media item** instead, so that workers regenerate all of its conversions (queued and non-queued) in parallel. This is especially useful on large libraries where files live on a remote disk such as S3 and most of the time is spent waiting on the network: the job downloads the original once and reuses it for every conversion and for the responsive images, and saves the media once. Make sure queue workers are running to actually process the dispatched jobs.

```bash
php artisan media-library:regenerate --queue-all
```

The jobs go to the `media-library.queue_connection_name` connection (or the application's default connection). `--queue-connection` picks another connection for a single run and implies `--queue-all`. On the `sync` connection the jobs run right away in the command's process, which gives you the single-download pipeline without workers:

```bash
php artisan media-library:regenerate --queue-connection=redis
php artisan media-library:regenerate --queue-connection=sync
```

The job is always dispatched onto the `media-library.queue_name` queue, regardless of the chosen connection, so make sure that connection's workers consume that queue.

> **Note**
> With `--queue-all` the per-conversion `queued()`/`nonQueued()` settings and any custom `media-library.jobs.perform_conversions` / `media-library.jobs.generate_responsive_images` jobs are **not** used. You can swap the per-media job through `media-library.jobs.regenerate_media`.

The command exits with a non-zero status when a media item could not be regenerated (or its job could not be dispatched), after listing the errors.

If your model registers conversions using the model instance (`$registerMediaConversionsUsingModelInstance = true`), add `--eager-models` to eager-load the related models and avoid an N+1 query while regenerating:

```bash
php artisan media-library:regenerate --eager-models
```

> **Note**
> `--eager-models` eager-loads the related model for every chunk of media. If your library contains media whose `model_type` points at a class that no longer exists, the eager load will fail the whole run. Leave it off (the default) for such libraries — without it, media with a missing model are simply skipped one by one.

If you want to regenerate images starting at a specific id (inclusive), you can use the `--starting-from-id` option

```bash
php artisan media-library:regenerate --starting-from-id=1
```

You can also start after the provided id by also passing the `--exclude-starting-id` or `-X` options

```bash
php artisan media-library:regenerate --starting-from-id=1 --exclude-starting-id
php artisan media-library:regenerate --starting-from-id=1 -X
```

The `--starting-from-id` option can also be combined with the `modelType` argument

```bash
php artisan media-library:regenerate "App\Models\Post" --starting-from-id=1
```
