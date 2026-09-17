<?php

use App\Backup\VerifyCardBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('verify ok when attachment content_length matches file size on disk', function () {
    Storage::fake('local');

    $pipeId = 12345;
    $cardId = 99999;
    $uuid = 'e4adb580-95f1-469f-bffc-f28a1767e07e';
    $filename = 'doc.pdf';
    $contents = 'hello-world-content';

    $cardData = [
        'id' => (string) $cardId,
        'title' => 'Card com Attachment',
        'attachments' => [
            [
                'filename' => $filename,
                'url' => "https://example.com/{$filename}",
                'path' => "uploads/{$uuid}/{$filename}",
                'content_length' => strlen($contents),
            ],
        ],
    ];

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData),
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuid}/{$filename}",
        $contents,
    );

    $result = app(VerifyCardBackup::class)->verify($pipeId, $cardId, 'Card com Attachment');

    expect($result->ok)->toBeTrue();
    expect($result->issues)->toBeEmpty();
});

test('verify reports issue when attachment content_length mismatches file size on disk', function () {
    Storage::fake('local');

    $pipeId = 12345;
    $cardId = 99999;
    $uuid = 'e4adb580-95f1-469f-bffc-f28a1767e07e';
    $filename = 'doc.pdf';
    $contents = 'hello-world-content';

    $cardData = [
        'id' => (string) $cardId,
        'title' => 'Card com Attachment',
        'attachments' => [
            [
                'filename' => $filename,
                'url' => "https://example.com/{$filename}",
                'path' => "uploads/{$uuid}/{$filename}",
                'content_length' => strlen($contents) + 100,
            ],
        ],
    ];

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData),
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuid}/{$filename}",
        $contents,
    );

    $result = app(VerifyCardBackup::class)->verify($pipeId, $cardId, 'Card com Attachment');

    expect($result->ok)->toBeFalse();
    expect($result->issues)->toHaveCount(1);
    expect($result->issues[0])->toContain('doc.pdf');
    expect($result->issues[0])->toContain('tamanho');
});

test('verify ok for legacy card without content_length when file exists', function () {
    Storage::fake('local');

    $pipeId = 12345;
    $cardId = 99999;
    $uuid = 'e4adb580-95f1-469f-bffc-f28a1767e07e';
    $filename = 'doc.pdf';
    $contents = 'legacy-content';

    $cardData = [
        'id' => (string) $cardId,
        'title' => 'Card Legado',
        'attachments' => [
            [
                'filename' => $filename,
                'url' => "https://example.com/{$filename}",
                'path' => "uploads/{$uuid}/{$filename}",
            ],
        ],
    ];

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData),
    );

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuid}/{$filename}",
        $contents,
    );

    $result = app(VerifyCardBackup::class)->verify($pipeId, $cardId, 'Card Legado');

    expect($result->ok)->toBeTrue();
    expect($result->issues)->toBeEmpty();
});
