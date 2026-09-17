<?php

namespace App\Models;

use App\Backup\BackupPaths;
use Database\Factories\PipeBackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PipeBackup extends Model
{
    /** @use HasFactory<PipeBackupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'pipe_id',
        'pipe_name',
        'cards_count',
        'attachments_count',
        'errors_count',
        'total_cards',
        'current_step',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pipe_id' => 'integer',
            'cards_count' => 'integer',
            'attachments_count' => 'integer',
            'errors_count' => 'integer',
            'total_cards' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PipeBackupError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(PipeBackupError::class);
    }

    /**
     * @return HasMany<PipeBackupCard, $this>
     */
    public function backupCards(): HasMany
    {
        return $this->hasMany(PipeBackupCard::class);
    }

    public function recalculateStatus(): void
    {
        $cards = $this->backupCards();

        $this->update([
            'cards_count' => (clone $cards)->whereIn('status', ['completed', 'completed_with_errors'])->count(),
            'attachments_count' => (clone $cards)->sum('attachments_count'),
            'errors_count' => (clone $cards)->sum('errors_count'),
        ]);

        $pendingOrProcessing = (clone $cards)->whereIn('status', ['pending', 'processing'])->count();

        if ($pendingOrProcessing === 0) {
            $hasFailed = (clone $cards)->where('status', 'failed')->exists();
            $this->update([
                'status' => $hasFailed ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
            ]);

            $this->generateIndexJson();
        }
    }

    private function generateIndexJson(): void
    {
        try {
            $cards = $this->backupCards()
                ->whereIn('status', ['completed', 'completed_with_errors'])
                ->get(['card_id', 'card_title']);

            $indexCards = $cards->map(fn (PipeBackupCard $card) => [
                'id' => $card->card_id,
                'title' => $card->card_title,
            ])->values()->toArray();

            $index = [
                'pipe_id' => $this->pipe_id,
                'backup_date' => now()->toIso8601String(),
                'total_cards' => $this->cards_count,
                'total_attachments_downloaded' => $this->attachments_count,
                'errors_count' => $this->errors_count,
                'cards' => $indexCards,
            ];

            Storage::disk('local')->put(
                BackupPaths::index($this->pipe_id),
                json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );

            Log::info("index.json gerado para pipe {$this->pipe_id} com ".count($indexCards).' cards.');
        } catch (\Throwable $e) {
            Log::warning("Falha ao gerar index.json para pipe {$this->pipe_id}: {$e->getMessage()}");
        }
    }
}
