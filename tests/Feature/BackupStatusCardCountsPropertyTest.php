<?php

use App\Models\PipeBackup;
use App\Models\PipeBackupCard;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function factoryState(string $status): string
{
    return match ($status) {
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'completed_with_errors' => 'completedWithErrors',
        'failed' => 'failed',
    };
}

test('status api returns correct card counts per status', function () {
    $statuses = ['pending', 'processing', 'completed', 'completed_with_errors', 'failed'];

    for ($i = 0; $i < 100; $i++) {
        PipeBackupCard::query()->delete();
        PipeBackup::query()->delete();

        $batchId = fake()->uuid();
        $pipeCount = fake()->numberBetween(1, 4);

        $expectedPerPipe = [];

        for ($p = 0; $p < $pipeCount; $p++) {
            $pipeBackup = PipeBackup::factory()->create([
                'batch_id' => $batchId,
                'status' => 'processing',
            ]);

            $cardCount = fake()->numberBetween(1, 12);
            $counts = array_fill_keys($statuses, 0);

            for ($c = 0; $c < $cardCount; $c++) {
                $status = fake()->randomElement($statuses);
                $counts[$status]++;

                PipeBackupCard::factory()->{factoryState($status)}()->create([
                    'pipe_backup_id' => $pipeBackup->id,
                ]);
            }

            $expectedPerPipe[$pipeBackup->id] = [
                'total' => $cardCount,
                'counts' => $counts,
            ];
        }

        $response = $this->getJson('/api/backup-status');
        $response->assertOk();

        $data = $response->json();

        foreach ($data['backups'] as $backupData) {
            $id = $backupData['id'];
            expect($backupData)->toHaveKey('card_status_summary');

            $summary = $backupData['card_status_summary'];
            $expected = $expectedPerPipe[$id];

            $actualTotal = $summary['pending'] + $summary['processing'] + $summary['completed']
                + $summary['completed_with_errors'] + $summary['failed'];

            expect($actualTotal)->toEqual($expected['total'], "Iteration {$i}: card counts should sum to {$expected['total']}, got {$actualTotal}");

            foreach ($statuses as $status) {
                expect($summary[$status])->toEqual($expected['counts'][$status], "Iteration {$i}: pipe {$id} status '{$status}' expected {$expected['counts'][$status]}, got {$summary[$status]}");
            }
        }
    }
});
