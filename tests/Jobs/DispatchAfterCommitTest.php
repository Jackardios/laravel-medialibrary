<?php

use Illuminate\Support\Facades\DB;

it('generates the responsive images of media added in a transaction once it is committed', function (bool $afterCommit, bool $generatedInTransaction) {
    config()->set('media-library.queue_conversions_after_database_commit', $afterCommit);

    DB::transaction(function () use ($generatedInTransaction) {
        $media = $this->testModel->addMedia($this->getTestJpg())->withResponsiveImages()->toMediaCollection();

        expect($media->fresh()->hasResponsiveImages())->toBe($generatedInTransaction);
    });

    expect($this->testModel->getFirstMedia()->hasResponsiveImages())->toBeTrue();
})->with([
    'after the commit' => [true, false],
    'right away' => [false, true],
]);
