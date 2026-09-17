<?php

use App\Backup\RetryFailedCards;
use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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
            'attachments_count' => $c->attachments_count,
            'error_message' => $c->error_message,
        ])->all();

        $failedCardIds = $failedCards->pluck('id')->all();

        $report = app(RetryFailedCards::class)->retry($batchId);

        expect($report->count)->toEqual($failedCount);
        expect($report->items)->toHaveCount($failedCount);

        foreach ($failedCardIds as $cardId) {
            $card = PipeBackupCard::find($cardId);
            expect($card->status)->toEqual('pending');
            expect($card->error_message)->toBeNull();
            expect($card->errors_count)->toEqual(0);
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

test('retry returns empty report when no failed cards', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'status' => 'completed',
    ]);

    PipeBackupCard::factory()
        ->count(5)
        ->completed()
        ->create(['pipe_backup_id' => $pipeBackup->id]);

    $report = app(RetryFailedCards::class)->retry($batchId);

    expect($report->isEmpty())->toBeTrue();
    expect($report->count)->toEqual(0);
    expect($report->items)->toBeEmpty();

    Queue::assertNothingPushed();
});

test('report items contain pipe and card details', function () {
    Queue::fake();

    $batchId = Str::uuid()->toString();

    $pipeBackup = PipeBackup::factory()->create([
        'batch_id' => $batchId,
        'pipe_id' => 12345,
        'pipe_name' => 'Test Pipe',
        'status' => 'completed_with_errors',
    ]);

    PipeBackupCard::factory()->failed()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => 67890,
        'card_title' => 'My Card',
    ]);

    $report = app(RetryFailedCards::class)->retry($batchId);

    expect($report->count)->toEqual(1);
    expect($report->items[0]->pipeId)->toEqual(12345);
    expect($report->items[0]->pipeName)->toEqual('Test Pipe');
    expect($report->items[0]->cardId)->toEqual(67890);
    expect($report->items[0]->cardTitle)->toEqual('My Card');
});
