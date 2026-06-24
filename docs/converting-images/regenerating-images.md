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

By default `--only-missing` trusts the `generated_conversions` column on the media record to decide what is missing, so it does not touch your filesystem to check. This is fast and avoids one request per conversion on remote disks such as S3. If files may have been deleted out-of-band (so a conversion is marked as generated in the database but no longer exists on disk), add `--verify-existence` to additionally confirm each conversion's file on the conversions disk:

```bash
php artisan media-library:regenerate --only-missing --verify-existence
```

If you want to force responsive images to be regenerated, you can use the `--with-responsive-images` option:

```bash
php artisan media-library:regenerate --with-responsive-images
```

## Regenerating in parallel

The command respects the `media-library.queue_connection_name` setting, just like the rest of the package:

- when that connection is `sync` (the default), every media item is regenerated **inline**, one after another, in the command's own process;
- when it is an asynchronous connection (e.g. `redis`), the command **dispatches one job per media item** onto your queue so that workers regenerate them in parallel.

This is especially useful on large libraries where files live on a remote disk such as S3 and most of the time is spent waiting on the network. Make sure queue workers are running to actually process the dispatched jobs.

You can override the connection for a single run with `--queue-connection`. This also lets you offload regeneration onto the queue even when `media-library.queue_connection_name` is `sync` — just point it at an asynchronous connection:

```bash
php artisan media-library:regenerate --queue-connection=redis
```

The job is always dispatched onto the `media-library.queue_name` queue, regardless of the chosen connection, so make sure that connection's workers consume that queue.

> **Note**
> When the work is queued, each media item is regenerated as a single job: the original is downloaded once and reused for every conversion and for the responsive images. As a consequence the per-conversion `queued()`/`nonQueued()` settings and any custom `media-library.jobs.perform_conversions` / `media-library.jobs.generate_responsive_images` jobs are **not** used by the regenerate command (the regular flow when adding media is unaffected). You can swap the per-media job through `media-library.jobs.regenerate_media`.

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
