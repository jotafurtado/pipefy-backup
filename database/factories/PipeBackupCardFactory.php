<?php

namespace Database\Factories;

use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipeBackupCard>
 */
class PipeBackupCardFactory extends Factory
{
    protected $model = PipeBackupCard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipe_backup_id' => PipeBackup::factory(),
            'card_id' => fake()->randomNumber(8, true),
            'card_title' => fake()->words(3, true),
            'status' => 'pending',
            'attachments_count' => 0,
            'errors_count' => 0,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state([
            'status' => 'processing',
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 10)),
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'attachments_count' => fake()->numberBetween(0, 10),
            'errors_count' => 0,
            'started_at' => now()->subMinutes(fake()->numberBetween(5, 30)),
            'completed_at' => now(),
        ]);
    }

    public function completedWithErrors(): static
    {
        return $this->state([
            'status' => 'completed_with_errors',
            'attachments_count' => fake()->numberBetween(1, 10),
            'errors_count' => fake()->numberBetween(1, 5),
            'started_at' => now()->subMinutes(fake()->numberBetween(5, 30)),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => 'failed',
            'errors_count' => fake()->numberBetween(1, 3),
            'error_message' => fake()->sentence(),
            'started_at' => now()->subMinutes(fake()->numberBetween(5, 30)),
            'completed_at' => now(),
        ]);
    }
}
