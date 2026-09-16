<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('retry only reprocesses failed cards via controller', function () {
    for ($i = 0; $i < 100; $i++) {
        Queue::fake();

        $pipeBackup = PipeBackup::factory()->create([
            'status' => 'completed_with_errors',
        ]);

        $totalCards = fake()->numberBetween(2, 10);
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
            'attachments_count' => $c->attachments_count,
            'error_message' => $c->error_message,
        ])->all();

        $failedCardIds = $failedCards->pluck('id')->all();

        $response = $this->postJson('/api/backup-retry');
        $response->assertOk();

        foreach ($failedCardIds as $cardId) {
            $card = PipeBackupCard::find($cardId);
            expect($card->status)->toEqual('pending');
            expect($card->error_message)->toBeNull();
        }

        foreach ($completedSnapshots as $snapshot) {
            $card = PipeBackupCard::find($snapshot['id']);
            expect($card->status)->toEqual($snapshot['status']);
            expect($card->attachments_count)->toEqual($snapshot['attachments_count']);
        }

        Queue::assertPushed(BackupCardJob::class, $failedCount);

        $pipeBackup->refresh();
        expect($pipeBackup->status)->toEqual('processing');

        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();
    }
});
