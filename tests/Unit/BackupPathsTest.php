<?php

use App\Backup\BackupPaths;

test('card json path matches legacy layout', function () {
    expect(BackupPaths::cardJson(12345, 67890))->toBe('pipefy-backup/12345/cards/67890.json');
});

test('attachment path matches legacy layout', function () {
    expect(BackupPaths::attachment(12345, 67890, 'report.pdf'))
        ->toBe('pipefy-backup/12345/attachments/67890/report.pdf');
});

test('index path matches legacy layout', function () {
    expect(BackupPaths::index(12345))->toBe('pipefy-backup/12345/cards/index.json');
});

test('attachment filename uses explicit filename when present', function () {
    expect(BackupPaths::attachmentFilename([
        'filename' => 'custom.pdf',
        'path' => '/uploads/other.pdf',
    ]))->toBe('custom.pdf');
});

test('attachment filename derives from path when filename is absent', function () {
    expect(BackupPaths::attachmentFilename([
        'path' => '/uploads/report.pdf',
    ]))->toBe('report.pdf');
});

test('attachment filename falls back to unknown when path basename is empty', function () {
    expect(BackupPaths::attachmentFilename(['path' => '']))->toBe('unknown');
    expect(BackupPaths::attachmentFilename([]))->toBe('unknown');
});

test('writer and reader resolve the same attachment path', function () {
    for ($i = 0; $i < 100; $i++) {
        $pipeId = fake()->numberBetween(100000, 9999999);
        $cardId = fake()->numberBetween(10000, 999999);
        $attachment = fake()->boolean()
            ? ['filename' => fake()->word().'.pdf']
            : ['path' => '/uploads/'.fake()->word().'.pdf'];

        $filename = BackupPaths::attachmentFilename($attachment);
        $path = BackupPaths::attachment($pipeId, $cardId, $filename);

        expect($path)->toBe("pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filename}");
    }
});
