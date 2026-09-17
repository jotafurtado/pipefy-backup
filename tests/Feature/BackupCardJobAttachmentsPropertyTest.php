<?php

use App\Backup\BackupPaths;
use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function generateAttachments(int $count): array
{
    $attachments = [];

    for ($i = 0; $i < $count; $i++) {
        $filename = fake()->unique()->lexify('????????').'.'.fake()->fileExtension();
        $uuid = fake()->uuid();
        $attachments[] = [
            'url' => 'https://example.com/files/'.$filename,
            'createdAt' => fake()->dateTimeThisYear()->format('c'),
            'path' => 'uploads/'.$uuid.'/'.$filename,
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
            $pathUuid = BackupPaths::attachmentPathUuid($attachment);
            $expectedPath = "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/{$attachment['filename']}";
            expect(Storage::disk('local')->exists($expectedPath))->toBeTrue("Iteration {$i}: Missing attachment {$attachment['filename']}");
        }

        $backupCard->refresh();
        expect($backupCard->attachments_count)->toEqual($attachmentCount, "Iteration {$i}: attachments_count mismatch");
    }
});

test('attachments with same filename but different uuids do not overwrite each other', function () {
    Storage::fake('local');

    $pipeId = 555555;
    $cardId = 77777;
    $filename = 'duplicate-name.pdf';

    $attachments = [
        [
            'url' => 'https://example.com/files/first.pdf',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'path' => 'uploads/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/'.$filename,
            'filename' => $filename,
        ],
        [
            'url' => 'https://example.com/files/second.pdf',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'path' => 'uploads/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb/'.$filename,
            'filename' => $filename,
        ],
    ];

    $cardData = ['id' => (string) $cardId, 'title' => 'Dup Card'];

    Storage::disk('local')->put(
        "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
        json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );

    $fakeResponses = [
        'https://example.com/files/first.pdf' => Http::response('content-first', 200),
        'https://example.com/files/second.pdf' => Http::response('content-second', 200),
    ];
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

    $pathA = "pipefy-backup/{$pipeId}/attachments/{$cardId}/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/{$filename}";
    $pathB = "pipefy-backup/{$pipeId}/attachments/{$cardId}/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb/{$filename}";

    expect(Storage::disk('local')->exists($pathA))->toBeTrue('First attachment should exist');
    expect(Storage::disk('local')->exists($pathB))->toBeTrue('Second attachment should exist');
    expect(Storage::disk('local')->get($pathA))->toBe('content-first');
    expect(Storage::disk('local')->get($pathB))->toBe('content-second');

    $backupCard->refresh();
    expect($backupCard->attachments_count)->toEqual(2);
});
