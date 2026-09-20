<?php

use App\Backup\BackupPaths;

test('attachmentPathUuid extracts uuid from uploads path', function () {
    $attachment = [
        'path' => 'uploads/e4adb580-95f1-469f-bffc-f28a1767e07e/produto_de_teste.png',
        'filename' => 'produto_de_teste.png',
    ];

    expect(BackupPaths::attachmentPathUuid($attachment))
        ->toBe('e4adb580-95f1-469f-bffc-f28a1767e07e');
});

test('attachmentPathUuid extracts uuid from path with spaces in filename', function () {
    $attachment = [
        'path' => 'uploads/51b76e16-df73-4606-b029-f407b554140e/download (1).jpg',
        'filename' => 'download (1).jpg',
    ];

    expect(BackupPaths::attachmentPathUuid($attachment))
        ->toBe('51b76e16-df73-4606-b029-f407b554140e');
});

test('attachmentPathUuid throws on malformed path', function () {
    $attachment = [
        'path' => '/uploads/somefile.png',
        'filename' => 'somefile.png',
    ];

    expect(fn () => BackupPaths::attachmentPathUuid($attachment))
        ->toThrow(InvalidArgumentException::class);
});

test('attachment with pathUuid returns nested path', function () {
    expect(BackupPaths::attachment(1000172, 36460290, 'file.png', 'e4adb580-95f1-469f-bffc-f28a1767e07e'))
        ->toBe('pipefy-backup/1000172/attachments/36460290/e4adb580-95f1-469f-bffc-f28a1767e07e/file.png');
});

test('attachment requires pathUuid', function () {
    expect(fn () => BackupPaths::attachment(1000172, 36460290, 'file.png'))
        ->toThrow(TypeError::class);
});

test('attachmentFilename strips unicode control and formatting characters', function () {
    $attachment = [
        'filename' => "kyoScan-\u{200e}10\u{200e}.\u{200e}22\u{200e}.\u{200e}2020-\u{200e}16\u{200e}.\u{200e}47\u{200e}.\u{200e}06.pdf",
    ];

    expect(BackupPaths::attachmentFilename($attachment))
        ->toBe('kyoScan-10.22.2020-16.47.06.pdf');
});
