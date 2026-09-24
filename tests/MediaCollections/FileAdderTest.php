<?php

namespace Spatie\MediaLibrary\Tests\MediaCollections;

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\FileAdder;

it('sanitizes filenames correctly', function () {
    /** @var FileAdder $adder */
    $adder = app(FileAdder::class);

    expect($adder->defaultSanitizer('valid-filename.jpg'))
        ->toEqual('valid-filename.jpg');

    expect($adder->defaultSanitizer('test one.pdf'))
        ->toEqual('test-one.pdf');

    expect($adder->defaultSanitizer('test#one.pdf'))
        ->toEqual('test-one.pdf');

    expect($adder->defaultSanitizer('some/test/file.pdf'))
        ->toEqual('some-test-file.pdf');

    expect($adder->defaultSanitizer('Scan-‎9‎.‎14‎.‎2022-‎7‎.‎23‎.‎28.pdf'))
        ->toEqual('Scan-9.14.2022-7.23.28.pdf');
});

it('sanitizes file names that are not valid on windows', function (string $fileName, string $sanitizedFileName) {
    expect(app(FileAdder::class)->defaultSanitizer($fileName))->toBe($sanitizedFileName);
})->with([
    'reserved characters' => ['a:b*c?d"e<f>g|h.jpg', 'a-b-c-d-e-f-g-h.jpg'],
    'trailing dots' => ['report.pdf..', 'report.pdf'],
    'trailing space and dot' => ['report.pdf. ', 'report.pdf'],
    'device name' => ['CON.txt', '_CON.txt'],
    'lowercase device name with several extensions' => ['lpt1.tar.gz', '_lpt1.tar.gz'],
    'device name without extension' => ['nul', '_nul'],
    'numbered port' => ['com9.jpg', '_com9.jpg'],
    'name that only starts like a device name' => ['console.jpg', 'console.jpg'],
    'only dots' => ['...', 'file'],
]);

it('will throw an exception if the sanitized file name is a php file name', function () {
    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('filename.php‎');
})->throws(FileNameNotAllowed::class);

it('will not throw an exception if the sanitized file name ends with php but is not a php file', function () {
    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('media-libraryJQwPHp');
})->throwsNoExceptions();

it('blocks a disallowed extension anywhere in the file name', function (string $fileName) {
    $adder = app(FileAdder::class);

    $adder->defaultSanitizer($fileName);
})->throws(FileNameNotAllowed::class)->with([
    'shell.php.jpg',
    'shell.PHP.jpg',
    'shell.php6',
    'shell.pht',
    'shell.phtml',
    'shell.shtml',
    'archive.phar',
    '.htaccess',
    'config.htaccess',
    '.user.ini',
    '.USER.INI',
    'web.config',
]);

it('allows files with multiple or non-dangerous extensions', function (string $fileName) {
    $adder = app(FileAdder::class);

    $adder->defaultSanitizer($fileName);
})->throwsNoExceptions()->with([
    'archive.tar.gz',
    'report.docx',
    'video.mp4',
    'image.jpeg',
    'backup.2026.05.zip',
    'document.pdf',
    'settings.ini',
    'app.config',
]);

it('respects a custom disallowed extensions config', function () {
    config()->set('media-library.disallowed_extensions', ['exe']);

    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('installer.exe');
})->throws(FileNameNotAllowed::class);

it('rejects files outside the allowlist when one is configured', function () {
    config()->set('media-library.allowed_extensions', ['jpg', 'png', 'pdf']);

    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('archive.zip');
})->throws(FileNameNotAllowed::class);

it('accepts files inside the allowlist when one is configured', function () {
    config()->set('media-library.allowed_extensions', ['jpg', 'png', 'pdf']);

    $adder = app(FileAdder::class);

    expect($adder->defaultSanitizer('photo.jpg'))->toEqual('photo.jpg');
});

it('still blocks dangerous interior extensions when an allowlist is configured', function () {
    config()->set('media-library.allowed_extensions', ['jpg']);

    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('shell.php.jpg');
})->throws(FileNameNotAllowed::class);

it('treats allowlist entries case-insensitively', function () {
    config()->set('media-library.allowed_extensions', ['JPG', '.PNG']);

    $adder = app(FileAdder::class);

    expect($adder->defaultSanitizer('photo.jpg'))->toEqual('photo.jpg');
    expect($adder->defaultSanitizer('photo.png'))->toEqual('photo.png');
});

it('rejects files without an extension when an allowlist is configured', function () {
    config()->set('media-library.allowed_extensions', ['jpg']);

    $adder = app(FileAdder::class);

    $adder->defaultSanitizer('Makefile');
})->throws(FileNameNotAllowed::class);
