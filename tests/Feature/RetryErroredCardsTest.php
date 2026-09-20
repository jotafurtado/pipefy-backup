<?php

use App\Backup\RetryFailedCards;
use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('retryErroredCards redispatches completed_with_errors cards and clears their error history', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'processing',
    ]);

    $okCards = PipeBackupCard::factory()
        ->count(2)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    $erroredCards = PipeBackupCard::factory()
        ->count(3)
        ->completedWithErrors()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    foreach ($erroredCards as $card) {
        PipeBackupError::create([
            'pipe_backup_id' => $pipeBackup->id,
            'pipe_backup_card_id' => $card->id,
            'type' => 'attachment_download',
            'card_id' => $card->card_id,
            'filename' => 'doc.pdf',
            'message' => 'timeout simulado',
        ]);
    }

    expect(PipeBackupError::count())->toEqual(3);

    $report = app(RetryFailedCards::class)->retryErroredCards($batchId);

    expect($report->count)->toEqual(3);
    expect($report->items)->toHaveCount(3);

    foreach ($erroredCards as $card) {
        expect(PipeBackupCard::find($card->id)->status)->toEqual('pending');
    }

    // Histórico de erros dos cards reprocessados é limpo para o painel refletir a nova tentativa
    expect(PipeBackupError::count())->toEqual(0);

    // Cards ok não são tocados
    foreach ($okCards as $card) {
        expect(PipeBackupCard::find($card->id)->status)->toEqual('completed');
    }

    Queue::assertPushed(BackupCardJob::class, 3);

    expect($pipeBackup->refresh()->status)->toEqual('processing');
});

test('retryErroredCards returns empty report when no partial cards', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(2)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    $report = app(RetryFailedCards::class)->retryErroredCards($batchId);

    expect($report->isEmpty())->toBeTrue();
    expect($report->count)->toEqual(0);

    Queue::assertNothingPushed();
});

test('api retry with scope errored reprocesses partial cards only', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create(['status' => 'processing']);

    PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->count(2)->completedWithErrors()->create(['pipe_backup_id' => $pipeBackup->id]);

    $response = $this->postJson('/api/backup-retry', ['scope' => 'errored']);

    $response->assertOk();
    $response->assertJson(['count' => 2, 'errored' => 2, 'failed' => 0]);

    // O card failed não foi tocado pelo scope errored
    expect(PipeBackupCard::where('status', 'failed')->count())->toEqual(1);
    expect(PipeBackupCard::where('status', 'pending')->count())->toEqual(2);
});

test('api retry default scope still reprocesses failed only', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create(['status' => 'processing']);

    PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->completedWithErrors()->create(['pipe_backup_id' => $pipeBackup->id]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertOk();
    $response->assertJson(['count' => 1, 'failed' => 1, 'errored' => 0]);

    expect(PipeBackupCard::where('status', 'completed_with_errors')->count())->toEqual(1);
});
