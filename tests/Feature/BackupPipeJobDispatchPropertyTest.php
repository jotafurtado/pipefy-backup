<?php

use App\Jobs\BackupCardJob;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return list<array<string, mixed>>
 */
function generateRandomCards(int $count): array
{
    $cards = [];
    for ($i = 0; $i < $count; $i++) {
        $cards[] = [
            'id' => (string) fake()->unique()->numberBetween(10000, 999999),
            'title' => fake()->sentence(),
            'assignees' => [],
            'comments' => [],
            'comments_count' => 0,
            'current_phase' => ['name' => fake()->words(2, true)],
            'done' => fake()->boolean(),
            'due_date' => null,
            'fields' => [],
            'labels' => [],
            'phases_history' => [],
            'url' => fake()->url(),
        ];
    }

    return $cards;
}

test('parent job creates n records and dispatches n card jobs', function () {
    for ($i = 0; $i < 100; $i++) {
        Queue::fake();
        Storage::fake('local');

        $n = fake()->numberBetween(1, 15);
        $pipeId = fake()->numberBetween(100000, 9999999);
        $cards = generateRandomCards($n);

        $pipeBackup = PipeBackup::factory()->create([
            'pipe_id' => $pipeId,
            'status' => 'pending',
        ]);

        $mock = $this->mock(PipefyService::class);
        $mock->shouldReceive('eachCardPage')
            ->once()
            ->with($pipeId, \Mockery::type('Closure'))
            ->andReturnUsing(function (int $pipeId, \Closure $onPage) use ($cards) {
                $onPage($cards, count($cards));

                return count($cards);
            });

        $job = new BackupPipeJob($pipeBackup->id, $pipeId);
        $job->handle(app(PipefyService::class));

        $pipeBackup->refresh();

        expect($pipeBackup->total_cards)->toEqual($n, "Iteration {$i}: total_cards should be {$n}, got {$pipeBackup->total_cards}");

        $backupCards = PipeBackupCard::where('pipe_backup_id', $pipeBackup->id)->get();
        expect($backupCards)->toHaveCount($n, "Iteration {$i}: Expected {$n} PipeBackupCard records, got {$backupCards->count()}");

        foreach ($backupCards as $backupCard) {
            expect($backupCard->status)->toEqual('pending', "Iteration {$i}: All PipeBackupCard records should have status 'pending'");
        }

        Queue::assertPushed(BackupCardJob::class, $n);

        $apiCardIds = array_map(fn (array $c) => (int) $c['id'], $cards);
        $dbCardIds = $backupCards->pluck('card_id')->toArray();
        sort($apiCardIds);
        sort($dbCardIds);
        expect($dbCardIds)->toEqual($apiCardIds, "Iteration {$i}: PipeBackupCard card_ids should match API card ids");

        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();
        fake()->unique(true);
        \Mockery::close();
    }
});
