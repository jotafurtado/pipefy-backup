<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('card with duplicate filenames downloads all attachments fresh and skips legacy migration', function () {
    Storage::fake('local');

    $pipeId = 555555;
    $cardId = 77777;
    $filename = 'duplicated-report.pdf';

    $uuidA = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $uuidB = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    $attachmentA = [
        'url' => 'https://example.com/files/a/'.$filename,
        'createdAt' => '2026-01-01T00:00:00+00:00',
        'path' => 'uploads/'.$uuidA.'/'.$filename,
        'filename' => $filename,
    ];

    $attachmentB = [
        'url' => 'https://example.com/files/b/'.$filename,
        'createdAt' => '2026-01-02T00:00:00+00:00',
        'path' => 'uploads/'.$uuidB.'/'.$filename,
        'filename' => $filename,
    ];

    $cardData = ['id' => (string) $cardId, 'title' => 'Duplicate Filename Card'];
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    // Pre-place files at legacy path (no pathUuid subdir) — must NOT be migrated
    $legacyPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filename}";
    Storage::disk('local')->put($legacyPath, 'legacy-content');

    Http::fake([
        'https://example.com/files/a/'.$filename => Http::response('content-a', 200, ['Content-Length' => '9']),
        'https://example.com/files/b/'.$filename => Http::response('content-b', 200, ['Content-Length' => '9']),
    ]);

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')->willReturn([$attachmentA, $attachmentB]);
    $this->app->instance(PipefyService::class, $mockPipefy);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $newPathA = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuidA}/{$filename}";
    $newPathB = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuidB}/{$filename}";

    // Both attachments downloaded fresh
    expect(Storage::disk('local')->exists($newPathA))->toBeTrue('First attachment should be downloaded');
    expect(Storage::disk('local')->exists($newPathB))->toBeTrue('Second attachment should be downloaded');
    expect(Storage::disk('local')->get($newPathA))->toBe('content-a');
    expect(Storage::disk('local')->get($newPathB))->toBe('content-b');

    // Legacy file untouched (not migrated)
    expect(Storage::disk('local')->exists($legacyPath))->toBeTrue('Legacy file must NOT be migrated for duplicate filenames');
    expect(Storage::disk('local')->get($legacyPath))->toBe('legacy-content');

    // Both URLs were called via Http (downloaded fresh)
    Http::assertSent(function ($request) use ($filename) {
        return $request->url() === 'https://example.com/files/a/'.$filename;
    });
    Http::assertSent(function ($request) use ($filename) {
        return $request->url() === 'https://example.com/files/b/'.$filename;
    });
});

test('card with unique filenames still migrates from legacy path without re-downloading', function () {
    Storage::fake('local');

    $pipeId = 555556;
    $cardId = 77778;
    $filenameA = 'unique-a.pdf';
    $filenameB = 'unique-b.pdf';

    $uuidA = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    $uuidB = 'dddddddd-dddd-dddd-dddd-dddddddddddd';

    $attachmentA = [
        'url' => 'https://example.com/files/'.$filenameA,
        'createdAt' => '2026-01-01T00:00:00+00:00',
        'path' => 'uploads/'.$uuidA.'/'.$filenameA,
        'filename' => $filenameA,
    ];

    $attachmentB = [
        'url' => 'https://example.com/files/'.$filenameB,
        'createdAt' => '2026-01-02T00:00:00+00:00',
        'path' => 'uploads/'.$uuidB.'/'.$filenameB,
        'filename' => $filenameB,
    ];

    $cardData = ['id' => (string) $cardId, 'title' => 'Unique Filenames Card'];
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    // Pre-place files at legacy paths
    $legacyPathA = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filenameA}";
    $legacyPathB = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filenameB}";
    Storage::disk('local')->put($legacyPathA, 'legacy-a');
    Storage::disk('local')->put($legacyPathB, 'legacy-b');

    Http::fake([
        'https://example.com/files/'.$filenameA => Http::response('', 200, ['Content-Length' => '8']),
        'https://example.com/files/'.$filenameB => Http::response('', 200, ['Content-Length' => '8']),
    ]);

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')->willReturn([$attachmentA, $attachmentB]);
    $this->app->instance(PipefyService::class, $mockPipefy);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $newPathA = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuidA}/{$filenameA}";
    $newPathB = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$uuidB}/{$filenameB}";

    // Migrated to new path
    expect(Storage::disk('local')->exists($newPathA))->toBeTrue('First file should be migrated');
    expect(Storage::disk('local')->exists($newPathB))->toBeTrue('Second file should be migrated');
    expect(Storage::disk('local')->get($newPathA))->toBe('legacy-a');
    expect(Storage::disk('local')->get($newPathB))->toBe('legacy-b');

    // Legacy paths emptied
    expect(Storage::disk('local')->exists($legacyPathA))->toBeFalse('Legacy A should be removed');
    expect(Storage::disk('local')->exists($legacyPathB))->toBeFalse('Legacy B should be removed');

    // No GET downloads should have happened (only HEAD allowed)
    Http::assertSent(fn ($request) => in_array($request->method(), ['HEAD']));
    Http::assertNotSent(fn ($request) => $request->method() === 'GET');
});
