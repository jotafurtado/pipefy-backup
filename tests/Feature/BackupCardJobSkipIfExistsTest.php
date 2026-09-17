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

function attachmentFixture(string $url, string $filename, string $uuid): array
{
    return [
        'url' => $url,
        'createdAt' => '2026-01-01T00:00:00+00:00',
        'path' => 'uploads/'.$uuid.'/'.$filename,
        'filename' => $filename,
    ];
}

function prewriteCardJson(int $pipeId, int $cardId, array $attachments = []): string
{
    $cardJsonPath = BackupPaths::cardJson($pipeId, $cardId);
    Storage::disk('local')->put(
        $cardJsonPath,
        json_encode(
            ['id' => (string) $cardId, 'title' => 'Card', 'attachments' => $attachments],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ),
    );

    return $cardJsonPath;
}

function dispatchBackupCard(int $pipeId, int $cardId, array $attachments): PipeBackupCard
{
    $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
    $backupCard = PipeBackupCard::factory()->pending()->create([
        'pipe_backup_id' => $pipeBackup->id,
        'card_id' => $cardId,
    ]);

    $mockPipefy = test()->createMock(PipefyService::class);
    $mockPipefy->method('getCardAttachments')->willReturn($attachments);

    $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
    $job->handle($mockPipefy);

    return $backupCard;
}

test('file exists with correct size is skipped', function () {
    Storage::fake('local');

    $pipeId = 100001;
    $cardId = 200001;
    $filename = 'already-here.pdf';
    $uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    $content = 'pre-existing-content';
    $contentLength = strlen($content);

    $attachment = attachmentFixture('https://example.com/files/'.$filename, $filename, $uuid);
    $cardJsonPath = prewriteCardJson($pipeId, $cardId, [$attachment]);

    $storagePath = BackupPaths::attachment($pipeId, $cardId, $filename, $uuid);
    Storage::disk('local')->put($storagePath, $content);

    $downloaded = false;
    Http::fake(function ($request) use ($attachment, $contentLength, &$downloaded) {
        if ($request->method() === 'HEAD' && $request->url() === $attachment['url']) {
            return Http::response('', 200, ['Content-Length' => (string) $contentLength]);
        }
        if ($request->method() === 'GET' && $request->url() === $attachment['url']) {
            $downloaded = true;

            return Http::response('should-not-download', 200);
        }

        return Http::response('', 404);
    });

    $backupCard = dispatchBackupCard($pipeId, $cardId, [$attachment]);

    expect($downloaded)->toBeFalse('GET download should not happen when file exists with correct size');
    expect(Storage::disk('local')->get($storagePath))->toBe($content, 'Existing file must be preserved');

    $cardJson = json_decode(Storage::disk('local')->get($cardJsonPath), true);
    expect($cardJson['attachments'][0]['content_length'])->toEqual($contentLength);

    $backupCard->refresh();
    expect($backupCard->attachments_count)->toEqual(1);
});

test('file missing is downloaded and content_length persisted', function () {
    Storage::fake('local');

    $pipeId = 100002;
    $cardId = 200002;
    $filename = 'missing.pdf';
    $uuid = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
    $content = 'downloaded-content';
    $contentLength = strlen($content);

    $attachment = attachmentFixture('https://example.com/files/'.$filename, $filename, $uuid);
    $cardJsonPath = prewriteCardJson($pipeId, $cardId, [$attachment]);

    $storagePath = BackupPaths::attachment($pipeId, $cardId, $filename, $uuid);

    Http::fake(function ($request) use ($attachment, $content, $contentLength) {
        if ($request->method() === 'HEAD' && $request->url() === $attachment['url']) {
            return Http::response('', 200, ['Content-Length' => (string) $contentLength]);
        }
        if ($request->method() === 'GET' && $request->url() === $attachment['url']) {
            return Http::response($content, 200);
        }

        return Http::response('', 404);
    });

    $backupCard = dispatchBackupCard($pipeId, $cardId, [$attachment]);

    expect(Storage::disk('local')->exists($storagePath))->toBeTrue();
    expect(Storage::disk('local')->get($storagePath))->toBe($content);

    $cardJson = json_decode(Storage::disk('local')->get($cardJsonPath), true);
    expect($cardJson['attachments'][0]['content_length'])->toEqual($contentLength);
});

test('file with wrong size is downloaded', function () {
    Storage::fake('local');

    $pipeId = 100003;
    $cardId = 200003;
    $filename = 'wrong-size.pdf';
    $uuid = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
    $existingContent = 'too-short';
    $downloadedContent = 'correct-and-longer-content';
    $contentLength = strlen($downloadedContent);

    $attachment = attachmentFixture('https://example.com/files/'.$filename, $filename, $uuid);
    $cardJsonPath = prewriteCardJson($pipeId, $cardId, [$attachment]);

    $storagePath = BackupPaths::attachment($pipeId, $cardId, $filename, $uuid);
    Storage::disk('local')->put($storagePath, $existingContent);

    $downloaded = false;
    Http::fake(function ($request) use ($attachment, $downloadedContent, $contentLength, &$downloaded) {
        if ($request->method() === 'HEAD' && $request->url() === $attachment['url']) {
            return Http::response('', 200, ['Content-Length' => (string) $contentLength]);
        }
        if ($request->method() === 'GET' && $request->url() === $attachment['url']) {
            $downloaded = true;

            return Http::response($downloadedContent, 200);
        }

        return Http::response('', 404);
    });

    $backupCard = dispatchBackupCard($pipeId, $cardId, [$attachment]);

    expect($downloaded)->toBeTrue('GET download should happen when size mismatches');
    expect(Storage::disk('local')->get($storagePath))->toBe($downloadedContent);

    $cardJson = json_decode(Storage::disk('local')->get($cardJsonPath), true);
    expect($cardJson['attachments'][0]['content_length'])->toEqual($contentLength);
});
