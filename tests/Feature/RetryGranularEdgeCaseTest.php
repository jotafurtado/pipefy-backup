<?php

use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('controller retry with no failed cards returns informative message', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(3)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    $response = $this->postJson('/api/backup-retry');

    $response->assertOk();
    $response->assertJson(['message' => 'Nenhum item com falha para reprocessar.']);

    Queue::assertNothingPushed();
});

test('command retry with no failed cards shows informative message', function () {
    Queue::fake();

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(3)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    $this->artisan('pipefy:backup-all', ['--retry' => true])
        ->expectsOutput('Nenhum card com falha para reprocessar.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('controller retry with no batch returns 404', function () {
    $response = $this->postJson('/api/backup-retry');

    $response->assertNotFound();
    $response->assertJson(['message' => 'Nenhum batch encontrado.']);
});
