<?php

use App\Models\PipeBackup;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('status api summary matches database counts', function () {
    for ($i = 0; $i < 100; $i++) {
        PipeBackup::query()->delete();

        $batchId = fake()->uuid();
        $n = fake()->numberBetween(1, 15);

        $backups = [];
        for ($j = 0; $j < $n; $j++) {
            $backups[] = PipeBackup::factory()->create([
                'batch_id' => $batchId,
                'cards_count' => fake()->numberBetween(0, 500),
                'attachments_count' => fake()->numberBetween(0, 200),
                'errors_count' => fake()->numberBetween(0, 50),
            ]);
        }

        $response = $this->getJson('/api/backup-status');
        $response->assertOk();

        $data = $response->json();

        $dbBackups = PipeBackup::where('batch_id', $batchId)->get();

        expect($data['summary']['total'])->toEqual($dbBackups->count(), "Iteration {$i}: total count mismatch");
        expect($data['summary']['completed'])->toEqual($dbBackups->where('status', 'completed')->count(), "Iteration {$i}: completed count mismatch");
        expect($data['summary']['processing'])->toEqual($dbBackups->where('status', 'processing')->count(), "Iteration {$i}: processing count mismatch");
        expect($data['summary']['pending'])->toEqual($dbBackups->where('status', 'pending')->count(), "Iteration {$i}: pending count mismatch");
        expect($data['summary']['failed'])->toEqual($dbBackups->where('status', 'failed')->count(), "Iteration {$i}: failed count mismatch");

        foreach ($data['backups'] as $backupData) {
            expect($backupData)->toHaveKey('pipe_name');
            expect($backupData)->toHaveKey('status');
            expect($backupData)->toHaveKey('cards_count');
            expect($backupData)->toHaveKey('attachments_count');
            expect($backupData)->toHaveKey('errors_count');
            expect($backupData)->toHaveKey('error_message');

            if ($backupData['status'] === 'failed') {
                expect($backupData['error_message'])->not->toBeNull("Iteration {$i}: failed backup must have error_message");
            }
        }
    }
});
