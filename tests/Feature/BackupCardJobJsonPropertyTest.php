<?php

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function generateRandomCardData(): array
{
    $faker = fake();

    return [
        'id' => (string) $faker->unique()->numberBetween(10000, 999999),
        'title' => $faker->sentence(),
        'assignees' => [['id' => (string) $faker->randomNumber(5), 'name' => $faker->name()]],
        'comments' => [['text' => $faker->sentence()]],
        'comments_count' => 1,
        'current_phase' => ['name' => $faker->words(2, true)],
        'done' => $faker->boolean(),
        'due_date' => $faker->optional()->date(),
        'fields' => [['name' => $faker->word(), 'value' => $faker->sentence()]],
        'labels' => [['name' => $faker->word()]],
        'phases_history' => [],
        'url' => $faker->url(),
    ];
}

test('card json is saved correctly to disk', function () {
    Storage::fake('local');

    for ($i = 0; $i < 100; $i++) {
        $cardData = generateRandomCardData();
        $cardId = (int) $cardData['id'];
        $pipeId = fake()->numberBetween(100000, 9999999);

        // Pre-write card JSON (como o BackupPipeJob faria)
        Storage::disk('local')->put(
            "pipefy-backup/{$pipeId}/cards/{$cardId}.json",
            json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $pipeBackup = PipeBackup::factory()->create(['pipe_id' => $pipeId, 'status' => 'processing']);
        $backupCard = PipeBackupCard::factory()->pending()->create(['pipe_backup_id' => $pipeBackup->id, 'card_id' => $cardId]);

        $mockPipefy = $this->createMock(PipefyService::class);
        $mockPipefy->method('getCardAttachments')->willReturn([]);
        $this->app->instance(PipefyService::class, $mockPipefy);

        $job = new BackupCardJob($backupCard->id, $pipeId, $cardId);
        $job->handle($mockPipefy);

        $jsonPath = "pipefy-backup/{$pipeId}/cards/{$cardId}.json";

        Storage::disk('local')->assertExists($jsonPath);

        $content = Storage::disk('local')->get($jsonPath);
        $decoded = json_decode($content, true);

        expect($decoded)->not->toBeNull("Iteration {$i}: JSON decode failed");
        expect($decoded['id'])->toEqual($cardData['id'], "Iteration {$i}: card id mismatch");
        expect($decoded['title'])->toEqual($cardData['title'], "Iteration {$i}: card title mismatch");
        expect($decoded)->toHaveKey('attachments');
    }
});
