<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('retry only redispatches failed cards', function () {
    for ($i = 0; $i < 100; $i++) {
        Queue::fake();

        $batchId = Str::uuid()->toString();

        $pipeBackup = PipeBackup::factory()->create([
            'batch_id' => $batchId,
            'status' => 'completed_with_errors',
        ]);

        $totalCards = fake()->numberBetween(2, 8);
        $failedCount = fake()->numberBetween(1, $totalCards - 1);
        $completedCount = $totalCards - $failedCount;

        $completedCards = PipeBackupCard::factory()
            ->count($completedCount)
            ->completed()
            ->create(['pipe_backup_id' => $pipeBackup->id]);

        $failedCards = PipeBackupCard::factory()
            ->count($failedCount)
            ->failed()
            ->create(['pipe_backup_id' => $pipeBackup->id]);

        $completedSnapshots = $completedCards->map(fn ($c) => [
            'id' => $c->id,
            'status' => $c->status,
        ])->all();

        $failedCardIds = $failedCards->pluck('id')->all();

        $this->artisan('pipefy:backup-all', ['--retry' => true])->assertSuccessful();

        foreach ($completedSnapshots as $snapshot) {
            $card = PipeBackupCard::find($snapshot['id']);
            expect($card->status)->toEqual($snapshot['status']);
        }

        foreach ($failedCardIds as $cardId) {
            $card = PipeBackupCard::find($cardId);
            expect($card->status)->toEqual('pending');
            expect($card->error_message)->toBeNull();
        }

        Queue::assertPushed(BackupCardJob::class, $failedCount);

        $pipeBackup->refresh();
        expect($pipeBackup->status)->toEqual('processing');

        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();
    }
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
