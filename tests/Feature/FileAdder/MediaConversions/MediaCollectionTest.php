<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Spatie\MediaLibrary\MediaCollections\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithConversion;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModelWithoutMediaConversions;

it('will use the disk from a media collection', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this->addMediaCollection('images')
                ->useDisk('secondMediaDisk');
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $this->assertFileDoesNotExist($this->getTempDirectory('media').'/'.$media->id.'/test.jpg');

    $this->assertFileExists($this->getTempDirectory('media2').'/'.$media->id.'/test.jpg');

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection('other-images');

    $this->assertFileExists($this->getTempDirectory('media').'/'.$media->id.'/test.jpg');
});

it('will not use the disk name of the collection if a diskname is specified while adding', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this->addMediaCollection('images')
                ->useDisk('secondMediaDisk');
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection('images', 'public');

    $this->assertFileExists($this->getTempDirectory('media').'/'.$media->id.'/test.jpg');

    $this->assertFileDoesNotExist($this->getTempDirectory('media2').'/'.$media->id.'/test.jpg');
});

it('can register media conversions when defining media collections', function () {
    $testModel = new class extends TestModelWithoutMediaConversions
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->registerMediaConversions(function (Media $media) {
                    $this
                        ->addMediaConversion('thumb')
                        ->greyscale();
                });
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection('images', 'public');

    $this->assertFileExists($this->getTempDirectory('media').'/'.$media->id.'/conversions/test-thumb.jpg');
});

it('will not use media conversions from an unrelated collection', function () {
    $testModel = new class extends TestModelWithoutMediaConversions
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->registerMediaConversions(function (Media $media) {
                    $this
                        ->addMediaConversion('thumb')
                        ->greyscale();
                });
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection('unrelated-collection');

    $this->assertFileDoesNotExist($this->getTempDirectory('media').'/'.$media->id.'/conversions/test-thumb.jpg');
});

it('will use conversions defined in conversions and conversions defined in collections', function () {
    $testModel = new class extends TestModelWithoutMediaConversions
    {
        public function registerMediaConversions(?Media $media = null): void
        {
            $this
                ->addMediaConversion('another-thumb')
                ->greyscale();
        }

        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->registerMediaConversions(function (?Media $media = null) {
                    $this
                        ->addMediaConversion('thumb')
                        ->greyscale();
                });
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->toMediaCollection('images', 'public');

    $this->assertFileExists($this->getTempDirectory('media').'/'.$media->id.'/conversions/test-thumb.jpg');

    $this->assertFileExists($this->getTempDirectory('media').'/'.$media->id.'/conversions/test-another-thumb.jpg');
});

it('can accept certain files', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->acceptsFile(fn (File $file) => $file->mimeType === 'image/jpeg');
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $this->expectException(FileUnacceptableForCollection::class);

    $model->addMedia($this->getTestPdf())->preservingOriginal()->toMediaCollection('images');
});

it('can guard against invalid mimetypes', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->acceptsMimeTypes(['image/jpeg']);
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $this->expectException(FileUnacceptableForCollection::class);

    $model->addMedia($this->getTestPdf())->preservingOriginal()->toMediaCollection('images');
});

it('can generate responsive images', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->withResponsiveImages();
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $media = $model->getMedia('images')->first();

    $this->assertEquals([
        '/media/1/responsive-images/test___media_library_original_340_280.jpg',
        '/media/1/responsive-images/test___media_library_original_284_234.jpg',
        '/media/1/responsive-images/test___media_library_original_237_195.jpg',
    ], $media->getResponsiveImageUrls());

    expect($media->getResponsiveImageUrls('non-existing-conversion'))->toEqual([]);
});

it('can generate responsive images on condition', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->withResponsiveImagesIf(true);
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $media = $model->getMedia('images')->first();

    $this->assertEquals([
        '/media/1/responsive-images/test___media_library_original_340_280.jpg',
        '/media/1/responsive-images/test___media_library_original_284_234.jpg',
        '/media/1/responsive-images/test___media_library_original_237_195.jpg',
    ], $media->getResponsiveImageUrls());

    expect($media->getResponsiveImageUrls('non-existing-conversion'))->toEqual([]);
});

test('if the single file method is specified it will delete all other media and will only keep the new one', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->singleFile();
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');
    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');
    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    expect($model->getMedia('images'))->toHaveCount(1);
});

test('a single file collection keeps the media that was just added even when it has a lower order column', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('avatar')
                ->singleFile();
        }
    };

    $bucket = $testModel::create(['name' => 'bucket']);

    $bucketMedia = [];
    foreach (range(1, 5) as $index) {
        $bucketMedia[$index] = $bucket
            ->addMedia($this->getTestJpg())
            ->preservingOriginal()
            ->usingFileName("bucket-{$index}.jpg")
            ->toMediaCollection('incoming');
    }

    $destination = $testModel::create(['name' => 'destination']);
    $bucketMedia[5]->copy($destination, 'avatar');

    $bucketMedia[3]->delete();
    $bucketMedia[4]->delete();
    $bucketMedia[5]->delete();

    $newBucketMedia = $bucket
        ->addMedia($this->getTestJpg())
        ->preservingOriginal()
        ->usingFileName('bucket-new.jpg')
        ->toMediaCollection('incoming');

    expect($newBucketMedia->order_column)->toBeLessThan(5);

    $newBucketMedia->copy($destination, 'avatar');

    expect($destination->fresh()->getMedia('avatar'))->toHaveCount(1);
    expect($destination->fresh()->getFirstMedia('avatar')->file_name)->toBe('bucket-new.jpg');
});

test('a single file collection keeps only the new media when one of its conversions fails', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->singleFile();
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    Event::listen(ConversionWillStartEvent::class, fn () => throw new RuntimeException('conversion failed'));

    expect(fn () => $model->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('new.jpg')->toMediaCollection('images'))
        ->toThrow(RuntimeException::class, 'conversion failed');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg']);
});

test('a single file collection keeps its media when adding a media whose conversion fails is rolled back', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->singleFile();
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    Event::listen(ConversionWillStartEvent::class, fn () => throw new RuntimeException('conversion failed'));

    expect(fn () => DB::transaction(fn () => $model->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('new.jpg')->toMediaCollection('images')))
        ->toThrow(RuntimeException::class, 'conversion failed');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['test.jpg'])
        ->and($media->getPath())->toBeFile();
});

test('a single file collection keeps its media when adding a media is rolled back', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->singleFile();
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $media = $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    expect(fn () => DB::transaction(function () use ($model) {
        $model->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('new.jpg')->toMediaCollection('images');

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class, 'rolled back');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['test.jpg'])
        ->and($media->getPath())->toBeFile();
});

test('if the only keeps latest method is specified it will delete all other media and will only keep the latest n ones', function () {
    $testModel = new class extends TestModelWithConversion
    {
        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->onlyKeepLatest(3);
        }
    };

    $model = $testModel::create(['name' => 'testmodel']);

    $firstFile = $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');
    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');
    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');
    $model->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection('images');

    $this->assertFalse($model->getMedia('images')->contains(fn ($model) => $model->is($firstFile)));
    expect($model->getMedia('images'))->toHaveCount(3);
});

function modelWithLimitedImagesCollection(int $limit): TestModelWithConversion
{
    $testModel = new class extends TestModelWithConversion
    {
        public static int $limit = 1;

        public function registerMediaCollections(): void
        {
            $this
                ->addMediaCollection('images')
                ->onlyKeepLatest(self::$limit);
        }
    };

    $testModel::$limit = $limit;

    return $testModel::create(['name' => 'testmodel']);
}

function addJpgToImages(TestModelWithConversion $model, string $fileName): Media
{
    return $model
        ->addMedia(test()->getTestJpg())
        ->preservingOriginal()
        ->usingFileName($fileName)
        ->toMediaCollection('images');
}

test('a single file collection keeps the last of the media added in one transaction', function () {
    $model = modelWithLimitedImagesCollection(1);

    [$first, $second] = DB::transaction(fn () => [
        addJpgToImages($model, 'a.jpg'),
        addJpgToImages($model, 'b.jpg'),
    ]);

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['b.jpg'])
        ->and($second->getPath())->toBeFile()
        ->and($first->getPath())->not->toBeFile();
});

test('a collection that only keeps the latest media keeps the last ones added in one transaction', function () {
    $model = modelWithLimitedImagesCollection(3);

    DB::transaction(function () use ($model) {
        foreach (['f1.jpg', 'f2.jpg', 'f3.jpg', 'f4.jpg', 'f5.jpg'] as $fileName) {
            addJpgToImages($model, $fileName);
        }
    });

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['f3.jpg', 'f4.jpg', 'f5.jpg']);
});

test('a single file collection holds the new media before the transaction around the addition is committed', function () {
    $model = modelWithLimitedImagesCollection(1);

    $old = addJpgToImages($model, 'old.jpg');

    DB::transaction(function () use ($model, $old) {
        $new = addJpgToImages($model, 'new.jpg');

        expect($model->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg'])
            ->and($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg'])
            ->and($model->fresh()->getFirstMedia('images')->is($new))->toBeTrue()
            ->and($old->getPath())->toBeFile();
    });

    expect($old->getPath())->not->toBeFile()
        ->and($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg']);
});

test('a collection that only keeps the latest media keeps the media and files it had when several additions are rolled back', function () {
    $model = modelWithLimitedImagesCollection(2);

    $first = addJpgToImages($model, 'o1.jpg');
    $second = addJpgToImages($model, 'o2.jpg');

    expect(fn () => DB::transaction(function () use ($model) {
        addJpgToImages($model, 'a.jpg');
        addJpgToImages($model, 'b.jpg');
        addJpgToImages($model, 'c.jpg');

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class, 'rolled back');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['o1.jpg', 'o2.jpg'])
        ->and($first->getPath())->toBeFile()
        ->and($second->getPath())->toBeFile();
});

test('a single file collection keeps the media a rolled back nested transaction replaced', function () {
    $model = modelWithLimitedImagesCollection(1);

    $old = addJpgToImages($model, 'old.jpg');

    $kept = DB::transaction(function () use ($model) {
        $kept = addJpgToImages($model, 'kept.jpg');

        try {
            DB::transaction(function () use ($model) {
                addJpgToImages($model, 'discarded.jpg');

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['kept.jpg']);

        return $kept;
    });

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['kept.jpg'])
        ->and($kept->getPath())->toBeFile()
        ->and($old->getPath())->not->toBeFile();
});

test('a single file collection removes the files of the media a committed nested transaction replaced once the outer one commits', function () {
    $model = modelWithLimitedImagesCollection(1);

    $old = addJpgToImages($model, 'old.jpg');

    DB::transaction(function () use ($model, $old) {
        DB::transaction(fn () => addJpgToImages($model, 'new.jpg'));

        expect($old->getPath())->toBeFile();
    });

    expect($old->getPath())->not->toBeFile()
        ->and($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg']);
});

test('a single file collection keeps its media and files when the outer transaction is rolled back after a nested one committed', function () {
    $model = modelWithLimitedImagesCollection(1);

    $old = addJpgToImages($model, 'old.jpg');

    expect(fn () => DB::transaction(function () use ($model) {
        DB::transaction(fn () => addJpgToImages($model, 'new.jpg'));

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class, 'rolled back');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['old.jpg'])
        ->and($old->getPath())->toBeFile();
});

test('a model that is deleted in the transaction it got media in can be committed', function () {
    $media = DB::transaction(function () {
        $model = modelWithLimitedImagesCollection(1);

        addJpgToImages($model, 'a.jpg');
        $media = addJpgToImages($model, 'b.jpg');

        $model->forceDelete();

        return $media;
    });

    expect(Media::count())->toBe(0)
        ->and($media->getPath())->not->toBeFile();
});

test('a collection that only keeps the latest media holds the same media as without a transaction when one is deleted in it', function () {
    $addAndDelete = function (TestModelWithConversion $model) {
        addJpgToImages($model, 'a.jpg')->delete();
        addJpgToImages($model, 'b.jpg');
    };

    $model = modelWithLimitedImagesCollection(2);
    addJpgToImages($model, 'o1.jpg');
    addJpgToImages($model, 'o2.jpg');
    $addAndDelete($model);

    $modelInTransaction = modelWithLimitedImagesCollection(2);
    addJpgToImages($modelInTransaction, 'o1.jpg');
    addJpgToImages($modelInTransaction, 'o2.jpg');
    DB::transaction(fn () => $addAndDelete($modelInTransaction));

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['o2.jpg', 'b.jpg'])
        ->and($modelInTransaction->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['o2.jpg', 'b.jpg']);
});

class CountingClearsTestModel extends TestModelWithConversion
{
    public static int $clears = 0;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->singleFile();
    }

    public function clearMediaCollectionExcept(string $collectionName = 'default', array|Collection|Media $excludedMedia = []): HasMedia
    {
        static::$clears++;

        return parent::clearMediaCollectionExcept($collectionName, $excludedMedia);
    }
}

it('removes the media that no longer fits through the model with and without a transaction', function () {
    CountingClearsTestModel::$clears = 0;

    $model = CountingClearsTestModel::create(['name' => 'testmodel']);

    addJpgToImages($model, 'a.jpg');
    addJpgToImages($model, 'b.jpg');

    expect(CountingClearsTestModel::$clears)->toBe(1);

    DB::transaction(fn () => addJpgToImages($model, 'c.jpg'));

    expect(CountingClearsTestModel::$clears)->toBe(2)
        ->and($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['c.jpg']);
});

it('leaves the collection alone when the model is gone by the time its media is added', function () {
    $model = modelWithLimitedImagesCollection(1);

    addJpgToImages($model, 'a.jpg');

    Media::created(fn () => DB::table('test_models')->where('id', $model->id)->delete());

    addJpgToImages($model, 'b.jpg');

    expect(Media::where('model_id', $model->id)->pluck('file_name')->all())->toBe(['a.jpg', 'b.jpg']);
});

test('a loaded media relation holds the new media of a single file collection inside a transaction', function () {
    $model = modelWithLimitedImagesCollection(1);

    addJpgToImages($model, 'old.jpg');

    $model->load('media');

    DB::transaction(function () use ($model) {
        addJpgToImages($model, 'new.jpg');

        expect($model->getMedia('images')->pluck('file_name')->all())->toBe(['new.jpg'])
            ->and($model->media->pluck('file_name')->all())->toBe(['new.jpg']);
    });
});

class OwnerNamePathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        return $media->model->name.'/'.$media->getKey();
    }
}

test('the files of replaced media are removed after the commit when the path generator needs the model that was deleted in the transaction', function () {
    config()->set('media-library.path_generator', OwnerNamePathGenerator::class);

    $model = modelWithLimitedImagesCollection(1);

    $old = addJpgToImages($model, 'old.jpg');
    $oldPath = $old->getPath();

    expect($oldPath)->toBeFile()->toContain('testmodel');

    DB::transaction(function () use ($model) {
        addJpgToImages($model, 'new.jpg');

        $model->deletePreservingMedia();
    });

    expect($oldPath)->not->toBeFile();
});

test('a single file collection keeps only the media of the attempt that is committed when the transaction is tried again', function () {
    $model = modelWithLimitedImagesCollection(1);

    addJpgToImages($model, 'old.jpg');

    $attempt = 0;

    DB::transaction(function () use ($model, &$attempt) {
        $attempt++;

        addJpgToImages($model, "new{$attempt}.jpg");

        if ($attempt === 1) {
            throw new PDOException('Deadlock found when trying to get lock; try restarting transaction');
        }
    }, 3);

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['new2.jpg'])
        ->and($model->getMedia('images')->pluck('file_name')->all())->toBe(['new2.jpg']);
});

test('a single file collection keeps only the new media when it is added after an addition was rolled back', function () {
    $model = modelWithLimitedImagesCollection(1);

    addJpgToImages($model, 'old.jpg');

    try {
        DB::transaction(function () use ($model) {
            addJpgToImages($model, 'ghost.jpg');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    addJpgToImages($model, 'next.jpg');

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['next.jpg']);
});

test('a single file collection keeps only the last media added through two instances of a model', function () {
    $model = modelWithLimitedImagesCollection(1);
    $sameModel = $model::find($model->id);

    DB::transaction(function () use ($model, $sameModel) {
        addJpgToImages($model, 'a1.jpg');
        addJpgToImages($sameModel, 'b1.jpg');
        addJpgToImages($model, 'a2.jpg');
        addJpgToImages($sameModel, 'b2.jpg');
    });

    expect($model->fresh()->getMedia('images')->pluck('file_name')->all())->toBe(['b2.jpg']);
});

it('removes the files of replaced media when the model has unsaved changes', function () {
    config()->set('media-library.path_generator', OwnerNamePathGenerator::class);

    $model = modelWithLimitedImagesCollection(1);
    $oldPath = addJpgToImages($model, 'old.jpg')->getPath();

    $model->name = 'renamed-in-memory';
    addJpgToImages($model, 'new.jpg');

    expect($oldPath)->not->toBeFile();
});
