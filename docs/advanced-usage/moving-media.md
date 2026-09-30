---
title: Moving media
weight: 7
---

You can move media from one model to another with the `move` method.

```php
$mediaItem = $model->getMedia()->first();

$movedMediaItem = $mediaItem->move($anotherModel, 'new-collection', 's3');
```

Any conversions defined on `$anotherModel` will be performed. The `name` and the `custom_properties` will be transferred as well.

`$anotherModel` has to be saved: moving media to a model that is not saved yet throws `MediaCannotBeUpdated`, because the media would be lost if the model is never saved. Media copied to an unsaved model is added once the model is created. Until then a copy of its file waits in the media library's temporary directory (`temporary_directory_path`, by default `storage/media-library/temp`); if the model is never saved, that copy stays there.

## Copying media

You can also copy media from one model with the `copy` method.

```php
$mediaItem = $model->getMedia()->first();

$copiedMediaItem = $mediaItem->copy($anotherModel, 'new-collection', 's3');
```
