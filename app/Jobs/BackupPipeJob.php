<?php

namespace App\Jobs;

use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Services\PipefyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupPipeJob implements ShouldQueue
{
    use Queueable;

    /** @var list<int> */
    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $pipeBackupId,
        public int $pipeId,
    ) {}

    /**
     * O job pode ser retentado por até 60 minutos após o dispatch.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(60);
    }

    public function handle(PipefyService $pipefy): void
    {
        $pipeBackup = PipeBackup::findOrFail($this->pipeBackupId);
        $pipeBackup->update([
            'status' => 'processing',
            'started_at' => now(),
            'current_step' => 'Consultando API...',
        ]);

        $totalCards = $pipefy->eachCardPage($this->pipeId, function (array $pageCards, int $total) use ($pipeBackup) {
            $pipeBackup->update([
                'total_cards' => $total,
                'current_step' => "Consultando API... {$total} cards encontrados",
            ]);

            foreach ($pageCards as $card) {
                $cardId = (int) $card['id'];

                // Salvar card JSON em disco imediatamente para liberar memória
                Storage::disk('local')->put(
                    "pipefy-backup/{$this->pipeId}/cards/{$cardId}.json",
                    json_encode($card, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                );

                $backupCard = PipeBackupCard::create([
                    'pipe_backup_id' => $pipeBackup->id,
                    'card_id' => $cardId,
                    'card_title' => $card['title'] ?? '',
                    'status' => 'pending',
                ]);

                BackupCardJob::dispatch(
                    $backupCard->id,
                    $this->pipeId,
                    $cardId,
                );
            }
        });

        if ($totalCards === 0) {
            $pipeBackup->update([
                'status' => 'completed',
                'completed_at' => now(),
                'current_step' => null,
            ]);

            return;
        }

        $pipeBackup->update(['current_step' => null]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Falha definitiva no backup do pipe {$this->pipeId}: {$exception->getMessage()}");

        $pipeBackup = PipeBackup::find($this->pipeBackupId);

        if ($pipeBackup) {
            $pipeBackup->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
