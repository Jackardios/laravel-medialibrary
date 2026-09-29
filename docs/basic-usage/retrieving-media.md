---
title: Retrieving media
weight: 3
---

To retrieve files you can use the `getMedia`-method:

```php
$mediaItems = $yourModel->getMedia();
```

To retrieve files from all collections you can use the `getMedia`-method with `*`:

```php
$mediaItems = $yourModel->getMedia("*");
```

The method returns a collection of `Media`-objects.

You can retrieve the URL and path to the file associated with the `Media`-object using  `getUrl`, `getTemporaryUrl` (for S3 only) and `getPath`:

```php
$publicUrl = $mediaItems[0]->getUrl();
$publicFullUrl = $mediaItems[0]->getFullUrl(); // URL including domain
$fullPathOnDisk = $mediaItems[0]->getPath();
$temporaryS3Url = $mediaItems[0]->getTemporaryUrl(Carbon::now()->addMinutes(5)); // Temporary S3 url (Keep first parameter null to use default expiration time)
```

If you want to retrieve versioned media URLs, for example when needing cache busting, you can enable versioning by setting the `version_urls` config value to `true` in your `media-library.php` config file. The `getUrl()` and `getFullUrl()` functions will return the URL with a version string based on the `updated_at` column of the media model.

Since retrieving the first media and the URL for the first media for an object is such a common scenario, the `getFirstMedia` and `getFirstMediaUrl` convenience-methods are also provided:

```php
$media = $yourModel->getFirstMedia();
$url = $yourModel->getFirstMediaUrl();
```

You can also retrieve the last media by using similar convenience-methods:

```php
$media = $yourModel->getLastMedia();
$url = $yourModel->getLastMediaUrl();
```

An instance of `Media` also has a name, by default its filename:

```php
echo $mediaItems[0]->name; // Display the name

$mediaItems[0]->name = 'new name';
$mediaItems[0]->save(); // The new name gets saved.
```

The name of a `Media` instance can be changed when it's added to the media library:

```php
$yourModel
   ->addMedia($pathToFile)
   ->usingName('new name')
   ->toMediaCollection();
```

The name of the uploaded file can be changed via the media-object:

```php
$mediaItems[0]->file_name = 'newFileName.jpg';
$mediaItems[0]->save(); // Saving will also rename the file on the filesystem.
```

The name of the uploaded file can also be changed when it gets added to the media-library:

```php
$yourModel
   ->addMedia($pathToFile)
   ->usingFileName('otherFileName.txt')
   ->toMediaCollection();
```

By default the file name is sanitized so it is valid on every platform: `#`, `/`, `\`, spaces and the characters Windows does not allow (`: * ? " < > |`) are replaced by `-`, trailing dots and spaces are removed, and Windows device names such as `CON` or `LPT1` get a `_` prefix (`CON.txt` becomes `_CON.txt`).

You can sanitize the filename using a callable:

```php
$yourModel
   ->addMedia($pathToFile)
   ->sanitizingFileName(function($fileName) {
      return strtolower(str_replace(['#', '/', '\\', ' '], '-', $fileName));
   })
   ->toMediaCollection();
```

**Security note.** By default, Media Library rejects uploads whose file name contains a potentially executable extension such as `.php` or `.phtml`. The check looks at every extension segment in the name, so `malicious.php.jpg` is blocked too. Files that configure PHP or the web server for their directory (`.htaccess`, `.user.ini`, `web.config`) are rejected as well. The check also applies to the name your own `sanitizingFileName` callable or file namer returns, and to the new name of a renamed media. A name that leaves its directory (`..`, a leading `/`, a backslash) or names a Windows drive or data stream (`:`) is rejected too, and the checks ignore the dots and spaces Windows drops from the end of a name (`shell.php.`).

The blocked extensions can be configured (and an opt-in allowlist enabled) in `config/media-library.php`:

```php
// Reject these extensions anywhere in the file name.
'disallowed_extensions' => ['php', 'phtml', 'phar', 'htaccess', /* ... */],

// When set, only accept uploads whose final extension is in this list.
'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
```

You can also retrieve the size of the file via  `size` and `human_readable_size` :

```php
$mediaItems[0]->size; // Returns the size in bytes
$mediaItems[0]->human_readable_size; // Returns the size in a human readable format (eg. 1,5 MB)
```

An instance of `Media` also contains the mime type of the file.

```php
$mediaItems[0]->mime_type; // Returns the mime type
```

You can remove something from the library by simply calling `delete` on an instance of `Media`:

```php
$mediaItems[0]->delete();
```

When a `Media` instance gets deleted all related files will be removed from the filesystem.

Deleting a model with associated media will also delete all associated files. If you use soft deletes, the associated files won't be deleted.

```php
$yourModel->delete(); // all associated files will be deleted as well
```

You may delete a model without removing associated media by calling the `deletePreservingMedia` method instead of `delete`.

```php
$yourModel->deletePreservingMedia(); // all associated files will be preserved 
```

If you want to remove all associated media in a specific collection you can use the `clearMediaCollection` method. It also accepts the collection name as an optional parameter:

```php
$yourModel->clearMediaCollection(); // all media in the "default" collection will be deleted

$yourModel->clearMediaCollection('images'); // all media in the images collection will be deleted
```

Also, there is a `clearMediaCollectionExcept` method which can be useful if you want to remove only few or some selected media in a collection. It accepts the collection name as the first argument and the media instance or collection of media instances which should not be removed as the second argument:

```php
$yourModel->clearMediaCollectionExcept('images', $yourModel->getFirstMedia()); // This will remove all associated media in the 'images' collection except the first media
```
