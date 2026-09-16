<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('attachment query failure results in completed with errors', function () {
    Storage::fake('local');

    $pipeId = 1234567;
    $cardId = 99999;
    $cardData = ['id' => (string) $cardId, 'title' => 'Test Card'];

    // Pre-write card JSON (como o BackupPipeJob faria)
    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')
        ->willThrowException(new \RuntimeException('API connection timeout'));

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $backupCard->refresh();
    expect($backupCard->status)->toEqual('completed_with_errors');
    expect($backupCard->completed_at)->not->toBeNull();

    Storage::disk('local')->assertExists("pipefy-backup/{$pipeId}/cards/{$cardId}.json");

    $error = PipeBackupError::where('pipe_backup_card_id', $backupCard->id)->first();
    expect($error)->not->toBeNull();
    expect($error->type)->toEqual('attachment_query');
    expect($error->message)->toContain('API connection timeout');
});

test('missing card json results in failed status', function () {
    Storage::fake('local');

    $pipeId = 1234567;
    $cardId = 88888;

    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = $this->createMock(PipefyService::class);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    $backupCard->refresh();
    expect($backupCard->status)->toEqual('failed');
    expect($backupCard->error_message)->toContain('Card JSON não encontrado');
    expect($backupCard->completed_at)->not->toBeNull();
});
