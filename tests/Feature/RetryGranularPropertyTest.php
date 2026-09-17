<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('controller retry delegates card retry to module', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'completed_with_errors',
    ]);

    PipeBackupCard::factory()->count(2)->completed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->failed()->create(['pipe_backup_id' => $pipeBackup->id]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertOk();
    $response->assertJsonFragment(['count' => 1]);

    Queue::assertPushed(BackupCardJob::class, 1);
});
