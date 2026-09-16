<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function generateAttachments(int $count): array
{
    $attachments = [];

    for ($i = 0; $i < $count; $i++) {
        $filename = fake()->unique()->lexify('????????').'.'.fake()->fileExtension();
        $attachments[] = [
            'url' => 'https://example.com/files/'.$filename,
            'createdAt' => fake()->dateTimeThisYear()->format('c'),
            'path' => '/uploads/'.$filename,
            'filename' => $filename,
        ];
    }

    fake()->unique(true);

    return $attachments;
}

test('attachments are downloaded to correct paths', function () {
    Storage::fake('local');

    for ($i = 0; $i < 100; $i++) {
        $attachmentCount = fake()->numberBetween(1, 5);
        $attachments = generateAttachments($attachmentCount);
        $pipeId = fake()->numberBetween(100000, 9999999);
        $cardId = fake()->numberBetween(10000, 999999);

        $cardData = ['id' => (string) $cardId, 'title' => fake()->sentence()];

        // Pre-write card JSON
        Storage::disk('local')->put(
            "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
            json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $fakeResponses = [];
        foreach ($attachments as $attachment) {
            $fakeResponses[$attachment['url']] = Http::response('file-content-'.$attachment['filename'], 200);
        }
        Http::fake($fakeResponses);

        $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
        $backupCard = PipeBackupCard::factory()->pending()->create([
            'pipe_backup_id' => $pipeBackup->id,
            'card_id' => $cardId,
        ]);

        $mockPipefy = $this->createMock(PipefyService::class);
        $mockPipefy->method('getCardAttachments')->willReturn($attachments);
        $this->app->instance(PipefyService::class, $mockPipefy);

        $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
        $job->handle($mockPipefy);

        foreach ($attachments as $attachment) {
            $expectedPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$attachment['filename']}";
            expect(Storage::disk('local')->exists($expectedPath))->toBeTrue("Iteration {$i}: Missing attachment {$attachment['filename']}");
        }

        $backupCard->refresh();
        expect($backupCard->attachments_count)->toEqual($attachmentCount, "Iteration {$i}: attachments_count mismatch");
    }
});
