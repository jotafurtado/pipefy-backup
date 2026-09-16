<?php

use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Services\PipefyService;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('successful job with zero cards transitions to completed', function () {
    for ($i = 0; $i < 100; $i++) {
        Queue::fake();

        $pipeBackup = PipeBackup::factory()->create([
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
        ]);

        $mock = $this->mock(PipefyService::class);
        $mock->shouldReceive('eachCardPage')
            ->once()
            ->with($pipeBackup->pipe_id, \Mockery::type('Closure'))
            ->andReturn(0);

        $job = new BackupPipeJob($pipeBackup->id, $pipeBackup->pipe_id);
        $job->handle(app(PipefyService::class));

        $pipeBackup->refresh();

        expect($pipeBackup->status)->toEqual('completed', "Iteration {$i}: Status should be 'completed' when no cards found");
        expect($pipeBackup->started_at)->not->toBeNull("Iteration {$i}: started_at should be set");
        expect($pipeBackup->completed_at)->not->toBeNull("Iteration {$i}: completed_at should be set");
        expect($pipeBackup->error_message)->toBeNull("Iteration {$i}: error_message should remain null on success");

        PipeBackup::query()->delete();
        \Mockery::close();
    }
});

test('failed job transitions to failed with error message', function () {
    for ($i = 0; $i < 100; $i++) {
        $faker = fake();
        $errorMessage = $faker->sentence();

        $pipeBackup = PipeBackup::factory()->create([
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
        ]);

        $job = new BackupPipeJob($pipeBackup->id, $pipeBackup->pipe_id);
        $exception = new \RuntimeException($errorMessage);

        $job->failed($exception);

        $pipeBackup->refresh();

        expect($pipeBackup->status)->toEqual('failed', "Iteration {$i}: Status should be 'failed' after definitive failure");
        expect($pipeBackup->completed_at)->not->toBeNull("Iteration {$i}: completed_at should be set on failure");
        expect($pipeBackup->error_message)->toEqual($errorMessage, "Iteration {$i}: error_message should contain the exception message");
    }
});
