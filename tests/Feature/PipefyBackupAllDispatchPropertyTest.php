<?php

use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dispatch creates n records and n jobs for n pipes', function () {
    for ($i = 0; $i < 100; $i++) {
        Queue::fake();

        $n = fake()->numberBetween(1, 20);
        $pipes = [];
        for ($j = 0; $j < $n; $j++) {
            $pipes[] = [
                'id' => fake()->unique()->randomNumber(7, true),
                'name' => fake()->unique()->words(3, true),
            ];
        }

        $mock = $this->mock(PipefyService::class);
        $mock->shouldReceive('getPipes')->once()->andReturn($pipes);

        config(['services.pipefy.organization_id' => '12345']);

        $this->artisan('pipefy:backup-all')->assertSuccessful();

        $backups = PipeBackup::all();

        expect($backups)->toHaveCount($n, "Iteration {$i}: Expected {$n} PipeBackup records, got {$backups->count()}");

        foreach ($backups as $backup) {
            expect($backup->status)->toEqual('pending');
        }

        Queue::assertPushed(BackupPipeJob::class, $n);

        PipeBackup::query()->delete();
        fake()->unique(true);
    }
});
