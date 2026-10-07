<?php

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\Support\FileName;

it('sanitizes a file name the way an added file is sanitized', function () {
    expect(FileName::sanitize("отчёт: март\u{00A0}2026.jpg"))->toBe('отчёт--март-2026.jpg')
        ->and(FileName::sanitize('some/test/file.pdf'))->toBe('some-test-file.pdf')
        ->and(FileName::sanitize('CON.txt'))->toBe('_CON.txt');
});

it('only returns names a media can be given', function (string $fileName) {
    expect(FileName::isAllowed(FileName::sanitize($fileName)))->toBeTrue();
})->with(['..', '.', '', '/', '\\', 'C:\\a.jpg', '../../x.jpg', " \u{00A0}.", 'a/./b.jpg', 'nul']);

it('refuses to sanitize a name the blocklist does not accept', function () {
    FileName::sanitize('report.pl.jpg');
})->throws(FileNameNotAllowed::class);

it('tells whether a media can be given a file name', function (string $fileName, bool $isAllowed) {
    expect(FileName::isAllowed($fileName))->toBe($isAllowed);
})->with([
    'plain name' => ['photo.jpg', true],
    'name with spaces' => ['my photo.jpg', true],
    'name in a directory of its own' => ['other/photo.jpg', true],
    'colon' => ['a:b.jpg', false],
    'backslash' => ['a\\b.jpg', false],
    'parent directory' => ['../x.jpg', false],
    'leading slash' => ['/x.jpg', false],
    'empty name' => ['', false],
    'blocked segment' => ['report.pl.jpg', false],
    'blocked extension' => ['shell.PHP', false],
    'blocked extension before the dots windows drops' => ['shell.php.', false],
    'server configuration' => ['.htaccess', false],
    'php configuration' => ['.user.ini', false],
]);

it('follows the configured extensions', function () {
    config()->set('media-library.allowed_extensions', ['jpg']);

    expect(FileName::isAllowed('photo.jpg'))->toBeTrue()
        ->and(FileName::isAllowed('photo.png'))->toBeFalse()
        ->and(FileName::isAllowed('photo'))->toBeFalse();
});

it('explains why a file name is refused', function (string $fileName, string $message) {
    expect(fn () => FileName::guard($fileName))->toThrow(FileNameNotAllowed::class, $message);
})->with([
    'colon' => ['a:b.jpg', 'The file name `a:b.jpg` is not allowed: it contains `:`, which names a drive or a data stream on Windows.'],
    'backslash' => ['a\\b.jpg', 'The file name `a\\b.jpg` is not allowed: it contains `\\`, which separates directories on Windows.'],
    'parent directory' => ['../x.jpg', 'The file name `../x.jpg` is not allowed: it leaves its directory.'],
    'blocked segment' => ['report.pl.jpg', 'The file name `report.pl.jpg` is not allowed: the extension `pl` poses a security risk.'],
    'server configuration' => ['web.config', 'The file name `web.config` is not allowed: such a file configures PHP or the web server.'],
]);

it('names the allow-list when it refuses an extension', function () {
    config()->set('media-library.allowed_extensions', ['jpg']);

    expect(fn () => FileName::guard('photo.png'))
        ->toThrow(FileNameNotAllowed::class, 'The file name `photo.png` is not allowed: the extension `png` is not one of the allowed extensions.')
        ->and(fn () => FileName::guard('photo'))
        ->toThrow(FileNameNotAllowed::class, 'The file name `photo` is not allowed: it has no extension, and only the allowed extensions are accepted.');
});

it('still names the original of a name that was sanitized', function () {
    expect(fn () => $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->usingFileName('my shell.php.jpg')->toMediaCollection())
        ->toThrow(FileNameNotAllowed::class, 'The file name `my shell.php.jpg` was sanitized to `my-shell.php.jpg`, which is not allowed: the extension `php` poses a security risk.');
});

it('explains why a media cannot be renamed without mentioning sanitizing', function () {
    $media = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $media->file_name = 'a:b.jpg';

    expect(fn () => $media->save())
        ->toThrow(FileNameNotAllowed::class, 'The file name `a:b.jpg` is not allowed: it contains `:`, which names a drive or a data stream on Windows.');
});

it('refuses every name as it is that it refuses to sanitize', function (string $fileName) {
    expect(fn () => FileName::sanitize($fileName))->toThrow(FileNameNotAllowed::class)
        ->and(FileName::isAllowed($fileName))->toBeFalse();
})->with([
    'no-break space' => ["shell.php\u{00A0}"],
    'ideographic space' => ["shell.php\u{3000}"],
    'zero width space' => ["shell.php\u{200B}"],
    'zero width space in the extension' => ["shell.p\u{200B}hp"],
    'nul' => ["shell.php\0"],
    'server configuration' => [".htaccess\u{00A0}"],
    'dot and no-break space' => ["shell.php.\u{00A0}."],
]);

it('agrees with the sanitizer on generated names', function () {
    $pieces = ['shell', 'photo', '.', '.', '.php', '.PhP', '.jpg', '.htaccess', '.user.ini', 'web.config', '.pl', ' ', "\u{00A0}", "\u{3000}", "\u{200B}", "\u{202E}", "\0", "\n", "\t", '-', '_', '/', '\\', ':', '*', "\xFF", 'ф', 'con', 'nul'];

    mt_srand(20261007);

    for ($i = 0; $i < 500; $i++) {
        $fileName = '';

        for ($length = mt_rand(1, 6); $length > 0; $length--) {
            $fileName .= $pieces[mt_rand(0, count($pieces) - 1)];
        }

        try {
            $sanitized = FileName::sanitize($fileName);
        } catch (FileNameNotAllowed) {
            expect(FileName::isAllowed($fileName))->toBeFalse('`'.json_encode($fileName, JSON_INVALID_UTF8_SUBSTITUTE).'` is allowed as it is, but cannot be sanitized');

            continue;
        }

        expect(FileName::isAllowed($sanitized))->toBeTrue('`'.json_encode($fileName, JSON_INVALID_UTF8_SUBSTITUTE).'` is sanitized to a name that is not allowed')
            ->and(FileName::sanitize($sanitized))->toBe($sanitized);
    }

    mt_srand();
});

it('refuses the names the filesystem cannot store, as renaming a media does', function (string $fileName) {
    $media = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $media->file_name = $fileName;

    expect(FileName::isAllowed($fileName))->toBeFalse()
        ->and(fn () => $media->save())->toThrow(FileNameNotAllowed::class, 'which the filesystem does not store');
})->with([
    'line break' => ["a\nb.jpg"],
    'soft hyphen' => ["a\u{00AD}b.jpg"],
    'emoji joined by a zero width joiner' => ["👨\u{200D}👩\u{200D}👧.jpg"],
    'zero width non-joiner' => ["می\u{200C}خواهم.jpg"],
    'invalid utf-8' => ["a\xFFb.jpg"],
]);

it('allows the names with unusual characters the filesystem stores', function (string $fileName) {
    $media = $this->testModel->addMedia($this->getTestJpg())->preservingOriginal()->toMediaCollection();

    $media->file_name = $fileName;
    $media->save();

    expect(FileName::isAllowed($fileName))->toBeTrue()
        ->and($media->getPath())->toBeFile();
})->with([
    'cyrillic' => ['фото 1.jpg'],
    'no-break space' => ["фото\u{00A0}1.jpg"],
    'emoji with a skin tone' => ['👍🏽.jpg'],
    'combining mark' => ["e\u{0301}.jpg"],
    'cjk' => ['写真.jpg'],
]);
