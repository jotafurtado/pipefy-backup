<?php

use App\Jobs\BackupCardJob;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('zero cards marks backup as completed', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'pending',
        'total_cards' => 0,
    ]);

    $mock = $this->mock(PipefyService::class);
    $mock->shouldReceive('eachCardPage')
        ->once()
        ->with($pipeBackup->pipe_id, \Mockery::type('Closure'))
        ->andReturn(0);

    $job = new BackupPipeJob($pipeBackup->id, $pipeBackup->pipe_id);
    $job->handle(app(PipefyService::class));

    $pipeBackup->refresh();

    expect($pipeBackup->status)->toEqual('completed');
    expect($pipeBackup->total_cards)->toEqual(0);
    expect($pipeBackup->completed_at)->not->toBeNull();
    expect(PipeBackupCard::where('pipe_backup_id', $pipeBackup->id)->get())->toHaveCount(0);
    Queue::assertNotPushed(BackupCardJob::class);
});

test('api failure marks backup as failed', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'pending',
    ]);

    $errorMessage = 'Connection timeout to Pipefy API';

    $mock = $this->mock(PipefyService::class);
    $mock->shouldReceive('eachCardPage')
        ->once()
        ->andThrow(new \RuntimeException($errorMessage));

    $job = new BackupPipeJob($pipeBackup->id, $pipeBackup->pipe_id);

    try {
        $job->handle(app(PipefyService::class));
    } catch (\RuntimeException) {
    }

    $job->failed(new \RuntimeException($errorMessage));

    $pipeBackup->refresh();

    expect($pipeBackup->status)->toEqual('failed');
    expect($pipeBackup->error_message)->toEqual($errorMessage);
    expect($pipeBackup->completed_at)->not->toBeNull();
    Queue::assertNotPushed(BackupCardJob::class);
});
