<?php

use App\Jobs\BackupCardJob;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

it('retries pipes that failed at the pipe level', function () {
    $batchId = fake()->uuid();

    $failedPipe = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'failed',
        'error_message' => 'Erro HTTP 401',
        'total_cards' => 0,
    ]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertSuccessful();
    $response->assertJsonFragment(['count' => 1]);

    $failedPipe->refresh();
    expect($failedPipe->status)->toBe('pending');
    expect($failedPipe->error_message)->toBeNull();

    Queue::assertPushed(BackupPipeJob::class, function ($job) use ($failedPipe) {
        return $job->pipeBackupId === $failedPipe->id
            && $job->pipeId === $failedPipe->pipe_id;
    });
});

it('retries individual failed cards', function () {
    $batchId = fake()->uuid();

    $backup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
        'total_cards' => 5,
    ]);

    PipeBackupCard::factory()->completed()->count(3)->create(['pipe_backup_id' => $backup->id]);
    $failedCard = PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $backup->id]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertSuccessful();
    $response->assertJsonFragment(['count' => 1]);

    $failedCard->refresh();
    expect($failedCard->status)->toBe('pending');

    Queue::assertPushed(BackupCardJob::class, 1);
});

it('retries both failed pipes and failed cards in same batch', function () {
    $batchId = fake()->uuid();

    PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'failed',
        'error_message' => 'Erro HTTP 401',
        'total_cards' => 0,
    ]);

    $completedBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
        'total_cards' => 3,
    ]);

    PipeBackupCard::factory()->completed()->count(2)->create(['pipe_backup_id' => $completedBackup->id]);
    PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $completedBackup->id]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertSuccessful();
    $response->assertJsonFragment(['count' => 2]);

    Queue::assertPushed(BackupPipeJob::class, 1);
    Queue::assertPushed(BackupCardJob::class, 1);
});

it('returns message when nothing to retry', function () {
    $batchId = fake()->uuid();

    PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
    ]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertSuccessful();
    $response->assertJsonFragment(['message' => 'Nenhum item com falha para reprocessar.']);
});
