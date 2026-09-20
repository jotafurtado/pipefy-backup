<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('command retry delegates to module and shows report', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed_with_errors',
    ]);

    PipeBackupCard::factory()->count(2)->completed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $pipeBackup->id]);

    $this->artisan('pipefy:backup-all', ['--retry' => true])
        ->expectsOutputToContain('Total de cards redespachados: 1')
        ->assertSuccessful();

    Queue::assertPushed(BackupCardJob::class, 1);
});

test('retry with no failures shows success message', function () {
    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(5)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    Queue::fake();

    $this->artisan('pipefy:backup-all', ['--retry' => true])
        ->expectsOutput('Nenhum card com falha para reprocessar.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command retry-errored delegates to module and shows report', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed_with_errors',
    ]);

    PipeBackupCard::factory()->count(2)->completed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->completedWithErrors()->create(['pipe_backup_id' => $pipeBackup->id]);

    $this->artisan('pipefy:backup-all', ['--retry-errored' => true])
        ->expectsOutputToContain('Total de cards com erros redespachados: 1')
        ->assertSuccessful();

    Queue::assertPushed(BackupCardJob::class, 1);
});

test('retry-errored with no partial errors shows success message', function () {
    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(5)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    Queue::fake();

    $this->artisan('pipefy:backup-all', ['--retry-errored' => true])
        ->expectsOutput('Nenhum card com erro parcial para reprocessar.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
