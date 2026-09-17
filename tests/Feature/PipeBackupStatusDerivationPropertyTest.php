<?php

use App\Backup\BackupPaths;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pipe status is derived correctly from card statuses', function () {
    Storage::fake('local');

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
        $expectedIndexCards = [];

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

            $card = PipeBackupCard::factory()->create([
                'pipe_backup_id' => $pipeBackup->id,
                'status' => $status,
                'attachments_count' => $attachments,
                'errors_count' => $errors,
                'started_at' => now()->subMinutes(5),
                'completed_at' => now(),
            ]);

            if (in_array($status, ['completed', 'completed_with_errors'], true)) {
                $expectedIndexCards[] = [
                    'id' => $card->card_id,
                    'title' => $card->card_title,
                ];
            }
        }

        $pipeBackup->recalculateStatus();
        $pipeBackup->refresh();

        $expectedStatus = $hasFailedCard ? 'completed_with_errors' : 'completed';
        expect($pipeBackup->status)->toEqual($expectedStatus);
        expect($pipeBackup->cards_count)->toEqual($completedOrPartialCount);
        expect($pipeBackup->attachments_count)->toEqual($expectedAttachments);
        expect($pipeBackup->errors_count)->toEqual($expectedErrors);
        expect($pipeBackup->completed_at)->not->toBeNull();

        Storage::disk('local')->assertExists(BackupPaths::index($pipeBackup->pipe_id));

        $index = json_decode(Storage::disk('local')->get(BackupPaths::index($pipeBackup->pipe_id)), true);
        expect($index['pipe_id'])->toEqual($pipeBackup->pipe_id);
        expect($index['total_cards'])->toEqual($completedOrPartialCount);
        expect($index['total_attachments_downloaded'])->toEqual($expectedAttachments);
        expect($index['errors_count'])->toEqual($expectedErrors);
        expect($index['cards'])->toEqualCanonicalizing($expectedIndexCards);

        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();
    }
});

test('pipe status stays processing while cards are pending', function () {
    Storage::fake('local');

    $pipeBackup = PipeBackup::factory()->create([
        'status' => 'processing',
        'completed_at' => null,
    ]);

    PipeBackupCard::factory()->completed()->create(['pipe_backup_id' => $pipeBackup->id]);
    PipeBackupCard::factory()->pending()->create(['pipe_backup_id' => $pipeBackup->id]);

    $pipeBackup->recalculateStatus();
    $pipeBackup->refresh();

    expect($pipeBackup->status)->toEqual('processing');
    expect($pipeBackup->completed_at)->toBeNull();
    Storage::disk('local')->assertMissing(BackupPaths::index($pipeBackup->pipe_id));
});
