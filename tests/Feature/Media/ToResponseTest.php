<?php

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\InvalidConversion;
use Spatie\TemporaryDirectory\TemporaryDirectory;

test('to response sends the content', function () {
    $media = $this->testModel->addMedia($testPdf = $this->getTestPdf())->preservingOriginal()->toMediaCollection();

    ob_start();
    @$media->toResponse(request())->sendContent();
    $content = ob_get_contents();
    ob_end_clean();

    $temporaryDirectory = (new TemporaryDirectory)->create();
    file_put_contents($temporaryDirectory->path('response.pdf'), $content);

    $this->assertFileEquals($testPdf, $temporaryDirectory->path('response.pdf'));
});

test('to response sends correct attachment header', function () {
    $media = $this->testModel->addMedia($this->getTestPdf())->preservingOriginal()->toMediaCollection();

    $response = $media->toResponse(request());

    expect($response->headers->get('Content-Disposition'))->toEqual('attachment; filename="test.pdf"');
});

test('to inline response sends correct attachment header', function () {
    $media = $this->testModel->addMedia($this->getTestPdf())->preservingOriginal()->toMediaCollection();

    $response = $media->toInlineResponse(request());

    expect($response->headers->get('Content-Disposition'))->toEqual('inline; filename="test.pdf"');
});

test('to response throws on non-existing conversions', function () {
    $media = $this->testModel
        ->addMedia($this->getTestPdf())
        ->preservingOriginal()
        ->toMediaCollection();

    expect(fn () => $media->toResponse(request(), 'non-existing-conversion'))
        ->toThrow(InvalidConversion::class, 'There is no conversion named `non-existing-conversion`');
});

test('to response throws before sending anything when the file is missing', function (string $conversion) {
    $media = $this->testModelWithConversion->addMedia($this->getTestJpg())->toMediaCollection();

    unlink($media->getPath($conversion));

    expect(fn () => $media->toResponse(request(), $conversion))->toThrow(FileDoesNotExist::class);
})->with(['original' => '', 'conversion' => 'thumb']);

test('to response sends a conversion under its own file name and mime type', function () {
    $media = $this->testModelWithConversion->addMedia($this->getTestPng())->toMediaCollection();

    $response = $media->toResponse(request(), 'thumb');

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="test-thumb.jpg"')
        ->and((int) $response->headers->get('Content-Length'))->toBe(filesize($media->getPath('thumb')));
});
