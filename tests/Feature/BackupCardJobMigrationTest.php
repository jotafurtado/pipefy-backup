<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('legacy path file is migrated to new path without re-downloading', function () {
    Storage::fake('local');

    $pipeId = 444444;
    $cardId = 66666;
    $filename = 'legacy-doc.pdf';
    $pathUuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    $attachment = [
        'url' => 'https://example.com/files/legacy-doc.pdf',
        'createdAt' => '2026-01-01T00:00:00+00:00',
        'path' => 'uploads/'.$pathUuid.'/'.$filename,
        'filename' => $filename,
    ];

    $cardData = ['id' => (string) $cardId, 'title' => 'Legacy Card'];
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    // Pre-place file at legacy path (no pathUuid subdir)
    $legacyPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filename}";
    Storage::disk('local')->put($legacyPath, 'legacy-content');

    // Http fake — HEAD returns Content-Length, GET should NOT be called
    Http::fake([
        'https://example.com/files/legacy-doc.pdf' => Http::response('', 200, ['Content-Length' => '14']),
    ]);

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')->willReturn([$attachment]);
    $this->app->instance(PipefyService::class, $mockPipefy);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $newPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/{$filename}";

    expect(Storage::disk('local')->exists($newPath))->toBeTrue('File should be migrated to new path');
    expect(Storage::disk('local')->exists($legacyPath))->toBeFalse('Legacy path should be empty after migration');
    expect(Storage::disk('local')->get($newPath))->toBe('legacy-content');
});

test('file missing from both paths triggers normal download', function () {
    Storage::fake('local');

    $pipeId = 444445;
    $cardId = 66667;
    $filename = 'new-doc.pdf';
    $pathUuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    $attachment = [
        'url' => 'https://example.com/files/new-doc.pdf',
        'createdAt' => '2026-01-01T00:00:00+00:00',
        'path' => 'uploads/'.$pathUuid.'/'.$filename,
        'filename' => $filename,
    ];

    $cardData = ['id' => (string) $cardId, 'title' => 'New Card'];
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    Http::fake([
        'https://example.com/files/new-doc.pdf' => Http::response('downloaded-content', 200, ['Content-Length' => '17']),
    ]);

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')->willReturn([$attachment]);
    $this->app->instance(PipefyService::class, $mockPipefy);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $newPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/{$filename}";

    expect(Storage::disk('local')->exists($newPath))->toBeTrue();
    expect(Storage::disk('local')->get($newPath))->toBe('downloaded-content');
});
