<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function callAggregatePipeBackupStatus(int $pipeBackupId): void
{
    $job = new BackupCardJob(0, 0, 0);
    $reflection = new \ReflectionMethod($job, 'aggregatePipeBackupStatus');
    $reflection->invoke($job, $pipeBackupId);
}

test('pipe status is derived correctly from card statuses', function () {
    $terminalStatuses = ['completed', 'completed_with_errors', 'failed'];

    for ($i = 0; $i < 100; $i++) {
        $pipeBackup = PipeBackup::factory()->create([
            'status' => 'processing',
            'cards_count' => 0,
            'attachments_count' => 0,
            'errors_count' => 0,
        ]);

        $cardCount = fake()->numberBetween(1, 10);
        $expectedAttachments = 0;
        $expectedErrors = 0;
        $hasFailedCard = false;
        $completedOrPartialCount = 0;

        for ($j = 0; $j < $cardCount; $j++) {
            $status = fake()->randomElement($terminalStatuses);
            $attachments = fake()->numberBetween(0, 20);
            $errors = $status === 'completed' ? 0 : fake()->numberBetween(0, 5);

            if ($status === 'failed') {
                $hasFailedCard = true;
            } else {
                $completedOrPartialCount++;
            }

            $expectedAttachments += $attachments;
            $expectedErrors += $errors;

            PipeBackupCard::factory()->create([
                'pipe_backup_id' => $pipeBackup->id,
                'status' => $status,
                'attachments_count' => $attachments,
                'errors_count' => $errors,
                'started_at' => now()->subMinutes(5),
                'completed_at' => now(),
            ]);
        }

        callAggregatePipeBackupStatus($pipeBackup->id);
        $pipeBackup->refresh();

        $expectedStatus = $hasFailedCard ? 'completed_with_errors' : 'completed';
        expect($pipeBackup->status)->toEqual($expectedStatus);
        expect($pipeBackup->cards_count)->toEqual($completedOrPartialCount);
        expect($pipeBackup->attachments_count)->toEqual($expectedAttachments);
        expect($pipeBackup->errors_count)->toEqual($expectedErrors);
        expect($pipeBackup->completed_at)->not->toBeNull();

        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();
    }
});
