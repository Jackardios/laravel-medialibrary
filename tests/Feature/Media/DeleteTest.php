<?php

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;
use Spatie\MediaLibrary\Support\FileRemover\FileBaseFileRemover;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Tests\Support\PathGenerator\CustomDirectoryStructurePathGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\TestCustomPathGenerator;
use Spatie\MediaLibrary\Tests\TestSupport\TestFileNamer;
use Spatie\MediaLibrary\Tests\TestSupport\TestModels\TestModel;
use Spatie\MediaLibrary\Tests\TestSupport\TestPathGenerator;

it('will remove the files when deleting an object that has media', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $this->testModel->delete();

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeFalse();
});

it('will remove the files when deleting a media instance', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $media->delete();

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeFalse();
});

it('will remove the files without extension', function () {
    $media = $this->testModel->addMedia($this->getTestImageWithoutExtension())->toMediaCollection('images');

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $media->delete();

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeFalse();
});

it('will remove files when deleting a media object with a custom path generator', function () {
    config(['media-library.path_generator' => TestPathGenerator::class]);

    $pathGenerator = new TestPathGenerator;

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');
    $path = $pathGenerator->getPath($media);

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $this->testModel->delete();

    expect(File::isDirectory($this->getTempDirectory($path)))->toBeFalse();
});

it('will remove files when deleting a media object with a custom path and directory generator', function () {
    config(['media-library.path_generator' => CustomDirectoryStructurePathGenerator::class]);
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $pathGenerator = new CustomDirectoryStructurePathGenerator;

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');
    $path = $pathGenerator->getPath($media);

    expect(File::exists($media->getPath()))->toBeTrue();

    $this->testModel->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
});

it('will remove converted files when deleting a media object with a custom path and directory generator and custom removal class', function () {
    config(['media-library.path_generator' => CustomDirectoryStructurePathGenerator::class]);
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $pathGenerator = new CustomDirectoryStructurePathGenerator;

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::exists($media->getPath()))->toBeTrue();
    expect(File::exists($media->getPath('thumb')))->toBeTrue();
    expect(File::exists($media->getPath('keep_original_format')))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(File::exists($media->getPath('thumb')))->toBeFalse();
    expect(File::exists($media->getPath('keep_original_format')))->toBeFalse();
});

it('will remove converted files and responsive images when deleting a media object with a custom path and directory generator and custom removal class', function () {
    config(['media-library.path_generator' => CustomDirectoryStructurePathGenerator::class]);
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $media = $this->testModelWithConversionsOnOtherDisk->addMedia($this->getTestPng())->toMediaCollection('images');
    $pathGenerator = new CustomDirectoryStructurePathGenerator;

    expect(File::exists($media->getPath()))->toBeTrue();
    expect(Storage::disk($media->disk)->exists($pathGenerator->getPathForResponsiveImages($media).'test___thumb_50_63.jpg'))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(Storage::disk($media->disk)->exists($pathGenerator->getPathForResponsiveImages($media).'test___thumb_50_63.jpg'))->toBeFalse();

});

it('will NOT remove other files within the same folder when deleting a media object with a custom path and directory generator', function () {
    config(['media-library.path_generator' => CustomDirectoryStructurePathGenerator::class]);
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');
    $media2 = $this->testModel->addMedia($this->getTestPng())->toMediaCollection('images');

    expect(File::exists($media->getPath()))->toBeTrue();
    expect(File::exists($media2->getPath()))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(File::exists($media2->getPath()))->toBeTrue();
});

it('will NOT remove other files within the same folder when deleting a media object with similar image names saved on same custom path and directory generator', function () {

    config(['media-library.path_generator' => TestCustomPathGenerator::class]);
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');
    $media2 = $this->testModel->addMedia($this->getTestImageEndingWithUnderscore())->toMediaCollection('images');

    expect(File::exists($media->getPath()))->toBeTrue();
    expect(File::exists($media2->getPath()))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(File::exists($media2->getPath()))->toBeTrue();
});

it('will remove conversion files when using custom file namer', function () {
    config(['media-library.path_generator' => DefaultPathGenerator::class]);
    config(['media-library.file_namer' => TestFileNamer::class]);

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::exists($media->getPath('thumb')))->toBeTrue();
    expect(File::exists($media->getPath('keep_original_format')))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(File::exists($media->getPath('thumb')))->toBeFalse();
    expect(File::exists($media->getPath('keep_original_format')))->toBeFalse();
    expect(File::exists($this->getMediaDirectory($media->getKey()).'/conversions'))->toBeFalse();
});

it('will remove responsive images when using custom file namer', function () {
    config(['media-library.path_generator' => DefaultPathGenerator::class]);
    config(['media-library.file_namer' => TestFileNamer::class]);

    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::exists($this->getMediaDirectory($media->getKey()).'/conversions'))->toBeTrue();
    expect(File::exists($this->getMediaDirectory($media->getKey()).'/responsive-images'))->toBeTrue();
    expect(File::exists($this->getMediaDirectory($media->getKey())))->toBeTrue();

    $media->delete();

    expect(File::exists($media->getPath()))->toBeFalse();
    expect(File::exists($this->getMediaDirectory($media->getKey()).'/conversions'))->toBeFalse();
    expect(File::exists($this->getMediaDirectory($media->getKey()).'/responsive-images'))->toBeFalse();
    expect(File::exists($this->getMediaDirectory($media->getKey())))->toBeFalse();
});

it('removes the responsive images of the original with the file based remover', function () {
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())
        ->usingName('Holiday picture')
        ->withResponsiveImages()
        ->toMediaCollection()
        ->fresh();

    $responsiveImagesDirectory = $this->getMediaDirectory("{$media->id}/responsive-images");
    expect($media->responsive_images)->toHaveKeys(['media_library_original', 'thumb']);

    $media->delete();

    expect(File::files($responsiveImagesDirectory))->toBe([]);
});

it('removes the responsive images of a renamed media with the file based remover', function () {
    config(['media-library.file_remover_class' => FileBaseFileRemover::class]);

    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection()->fresh();

    $media->file_name = 'renamed.jpg';
    $media->save();

    $media->fresh()->delete();

    expect(File::files($this->getMediaDirectory("{$media->id}/responsive-images")))->toBe([]);
});

it('removes the responsive images of conversions the model no longer registers', function () {
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->toMediaCollection();

    Media::whereKey($media->id)->update(['model_type' => TestModel::class]);

    Media::find($media->id)->delete();

    expect($this->getMediaDirectory("{$media->id}/responsive-images"))->not->toBeDirectory();
});

it('will not remove the files when should delete preserving media returns true', function () {
    $testModelClass = new class extends TestModel
    {
        public function shouldDeletePreservingMedia(): bool
        {
            return true;
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    $testModel = $testModel->fresh();

    $testModel->delete();

    $this->assertNotNull(Media::find($media->id));
});

it('will remove the files when should delete preserving media returns false', function () {
    $testModelClass = new class extends TestModel
    {
        public function shouldDeletePreservingMedia(): bool
        {
            return false;
        }
    };

    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    $testModel = $testModel->fresh();

    $testModel->delete();

    expect(Media::find($media->id))->toBeNull();
});

it('will not remove the file when model uses softdelete', function () {
    $testModelClass = new class extends TestModel
    {
        use SoftDeletes;
    };

    /** @var TestModel $testModel */
    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $testModel = $testModel->fresh();

    $testModel->delete();

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();
});

it('will remove the file when model uses softdelete with force', function () {
    $testModelClass = new class extends TestModel
    {
        use SoftDeletes;
    };

    /** @var TestModel $testModel */
    $testModel = $testModelClass::find($this->testModel->id);

    $media = $testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeTrue();

    $testModel = $testModel->fresh();

    $testModel->forceDelete();

    expect(File::isDirectory($this->getMediaDirectory($media->id)))->toBeFalse();
});

it('removes the files of a media stored without responsive images', function (string $fileRemover) {
    config(['media-library.file_remover_class' => $fileRemover]);

    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection('images');
    DB::table('media')->where('id', $media->id)->update(['responsive_images' => 'null']);

    $media->fresh()->delete();

    expect($media->getPath())->not->toBeFile()
        ->and($media->getPath('thumb'))->not->toBeFile();
})->with([DefaultFileRemover::class, FileBaseFileRemover::class]);

class DiskRecordingDeletes extends FilesystemAdapter
{
    public static array $deleted = [];

    public function delete($paths)
    {
        array_push(static::$deleted, ...Arr::wrap($paths));

        return parent::delete($paths);
    }
}

it('removes each file of a media once', function () {
    $media = $this->testModelWithResponsiveImages->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection()->fresh();

    $disk = Storage::disk('public');
    Storage::set('public', new DiskRecordingDeletes($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()));
    DiskRecordingDeletes::$deleted = [];

    $media->delete();

    expect(DiskRecordingDeletes::$deleted)->not->toBeEmpty()
        ->and(array_unique(DiskRecordingDeletes::$deleted))->toBe(DiskRecordingDeletes::$deleted)
        ->and($this->getMediaDirectory($media->id))->not->toBeDirectory();
});

it('removes the conversions and responsive images stored on a separate conversions disk', function () {
    $media = $this->testModelWithConversionsOnOtherDisk
        ->addMedia($this->getTestJpg())
        ->withResponsiveImages()
        ->toMediaCollection('thumb');

    $conversionsDirectory = $this->getTempDirectory("media2/{$media->id}");

    expect("{$conversionsDirectory}/conversions/test-thumb.jpg")->toBeFile()
        ->and(File::files("{$conversionsDirectory}/responsive-images"))->not->toBeEmpty();

    $media->delete();

    expect($conversionsDirectory)->not->toBeDirectory()
        ->and($this->getMediaDirectory($media->id))->not->toBeDirectory();
});

class SoftDeletingMedia extends Media
{
    use SoftDeletes;
}

it('keeps the files of a soft deleted media until it is force deleted', function () {
    Schema::table('media', fn (Blueprint $table) => $table->softDeletes());

    config()->set('media-library.media_model', SoftDeletingMedia::class);

    (new MediaLibraryServiceProvider(app()))->register()->boot();

    $media = $this->testModel->addMedia($this->getTestJpg())->toMediaCollection('images');

    expect($media)->toBeInstanceOf(SoftDeletingMedia::class);

    $media->delete();

    expect($media->getPath())->toBeFile();

    $media->forceDelete();

    expect($media->getPath())->not->toBeFile()
        ->and($this->getMediaDirectory($media->id))->not->toBeDirectory();
});
