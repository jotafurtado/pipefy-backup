<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PipeBackup>
 */
class PipeBackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement(['pending', 'processing', 'completed', 'failed']);

        return [
            'batch_id' => fake()->uuid(),
            'pipe_id' => fake()->randomNumber(7, true),
            'pipe_name' => fake()->words(3, true),
            'cards_count' => in_array($status, ['completed', 'processing']) ? fake()->numberBetween(10, 200) : 0,
            'attachments_count' => in_array($status, ['completed', 'processing']) ? fake()->numberBetween(0, 50) : 0,
            'errors_count' => $status === 'failed' ? fake()->numberBetween(1, 5) : 0,
            'total_cards' => in_array($status, ['processing', 'completed', 'failed']) ? fake()->numberBetween(50, 300) : 0,
            'current_step' => $status === 'processing' ? fake()->randomElement(['Consultando API...', 'Processando card 15/100', 'Baixando attachments']) : null,
            'status' => $status,
            'error_message' => $status === 'failed' ? fake()->sentence() : null,
            'started_at' => in_array($status, ['processing', 'completed', 'failed']) ? now()->subMinutes(fake()->numberBetween(1, 60)) : null,
            'completed_at' => in_array($status, ['completed', 'failed']) ? now() : null,
        ];
    }
}
