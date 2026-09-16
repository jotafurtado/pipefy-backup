<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('attachment failure does not block remaining downloads', function () {
    Storage::fake('local');

    for ($i = 0; $i < 100; $i++) {
        $totalAttachments = fake()->numberBetween(2, 6);
        $failCount = fake()->numberBetween(1, $totalAttachments - 1);

        $attachments = [];
        $failIndices = array_rand(range(0, $totalAttachments - 1), $failCount);
        if (! is_array($failIndices)) {
            $failIndices = [$failIndices];
        }

        $fakeResponses = [];
        for ($j = 0; $j < $totalAttachments; $j++) {
            $filename = fake()->unique()->lexify('????????').'.'.fake()->fileExtension();
            $url = 'https://example.com/files/'.$filename;
            $attachments[] = [
                'url' => $url,
                'createdAt' => fake()->dateTimeThisYear()->format('c'),
                'path' => '/uploads/'.$filename,
                'filename' => $filename,
            ];

            if (in_array($j, $failIndices)) {
                $fakeResponses[$url] = Http::response('error', 500);
            } else {
                $fakeResponses[$url] = Http::response('file-content', 200);
            }
        }
        fake()->unique(true);
        Http::fake($fakeResponses);

        $pipeId = fake()->numberBetween(100000, 9999999);
        $cardId = fake()->numberBetween(10000, 999999);

        // Pre-write card JSON
        Storage::disk('local')->put(
            "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
            json_encode(['id' => (string) $cardId, 'title' => fake()->sentence()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
        $backupCard = PipeBackupCard::factory()->pending()->create([
            'pipe_backup_id' => $pipeBackup->id,
            'card_id' => $cardId,
        ]);

        $mockPipefy = $this->createMock(PipefyService::class);
        $mockPipefy->method('getCardAttachments')->willReturn($attachments);

        $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
        $job->handle($mockPipefy);

        $expectedSuccess = $totalAttachments - $failCount;
        $backupCard->refresh();

        expect($backupCard->attachments_count)->toEqual($expectedSuccess, "Iteration {$i}: Expected {$expectedSuccess} successful downloads, got {$backupCard->attachments_count}");

        $downloadErrors = PipeBackupError::where('pipe_backup_card_id', $backupCard->id)
            ->where('type', 'attachment_download')
            ->count();

        expect($downloadErrors)->toEqual($failCount, "Iteration {$i}: Expected {$failCount} errors, got {$downloadErrors}");

        expect($backupCard->status)->toEqual('completed_with_errors', "Iteration {$i}: status should be completed_with_errors");
    }
});
