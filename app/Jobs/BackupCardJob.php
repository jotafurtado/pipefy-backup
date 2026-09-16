<?php

namespace App\Jobs;

use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use App\Services\PipefyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BackupCardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 60, 120];

    /**
     * @param  array<string, mixed>  $cardData
     */
    public function __construct(
        public int $pipeBackupCardId,
        public int $pipeId,
        public int $cardId,
    ) {}

    public function handle(PipefyService $pipefy): void
    {
        $backupCard = PipeBackupCard::findOrFail($this->pipeBackupCardId);
        $backupCard->update(['status' => 'processing', 'started_at' => now()]);

        $hasErrors = false;

        // Ler card data do disco (salvo pelo BackupPipeJob)
        $cardJsonPath = "pipefy-backup/{$this->pipeId}/cards/{$this->cardId}.json";
        $cardJson = Storage::disk('local')->get($cardJsonPath);

        if (! $cardJson) {
            $backupCard->update([
                'status' => 'failed',
                'error_message' => "Card JSON não encontrado: {$cardJsonPath}",
                'completed_at' => now(),
            ]);
            $this->aggregatePipeBackupStatus($backupCard->pipe_backup_id);

            return;
        }

        $cardData = json_decode($cardJson, true);

        // Buscar attachments via API
        $attachments = [];
        try {
            $attachments = $pipefy->getCardAttachments($this->cardId);
        } catch (\Throwable $e) {
            $hasErrors = true;
            $this->recordError($backupCard, 'attachment_query', $this->cardId, null, $e->getMessage());
        }

        // Atualizar card JSON com attachments
        $cardData['attachments'] = $attachments;

        try {
            Storage::disk('local')->put(
                $cardJsonPath,
                json_encode($cardData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable $e) {
            $backupCard->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->aggregatePipeBackupStatus($backupCard->pipe_backup_id);

            return;
        }

        // Baixar attachments
        $attachmentsDownloaded = 0;
        foreach ($attachments as $attachment) {
            try {
                $this->downloadAttachment($attachment, $this->cardId);
                $attachmentsDownloaded++;
            } catch (\Throwable $e) {
                $hasErrors = true;
                $this->recordError($backupCard, 'attachment_download', $this->cardId, $attachment['filename'] ?? null, $e->getMessage());
            }
        }

        $backupCard->update([
            'status' => $hasErrors ? 'completed_with_errors' : 'completed',
            'attachments_count' => $attachmentsDownloaded,
            'errors_count' => $backupCard->fresh()->pipeBackupErrors()->count(),
            'completed_at' => now(),
        ]);

        $this->aggregatePipeBackupStatus($backupCard->pipe_backup_id);
    }

    public function failed(\Throwable $exception): void
    {
        $backupCard = PipeBackupCard::find($this->pipeBackupCardId);

        if ($backupCard) {
            $backupCard->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            $this->aggregatePipeBackupStatus($backupCard->pipe_backup_id);
        }
    }

    private function recordError(PipeBackupCard $backupCard, string $type, int $cardId, ?string $filename, string $message): void
    {
        PipeBackupError::create([
            'pipe_backup_id' => $backupCard->pipe_backup_id,
            'pipe_backup_card_id' => $backupCard->id,
            'type' => $type,
            'card_id' => $cardId,
            'filename' => $filename,
            'message' => $message,
        ]);
    }

    private function downloadAttachment(array $attachment, int $cardId): void
    {
        $filename = $attachment['filename'] ?? basename($attachment['path'] ?? '') ?: 'unknown';
        $attachmentDir = "pipefy-backup/{$this->pipeId}/attachments/{$cardId}";
        $storagePath = "{$attachmentDir}/{$filename}";
        $fullPath = Storage::disk('local')->path($storagePath);
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $response = Http::withOptions(['sink' => $fullPath])->get($attachment['url']);

        if ($response->failed()) {
            throw new \RuntimeException("Download falhou: HTTP {$response->status()}");
        }
    }

    private function aggregatePipeBackupStatus(int $pipeBackupId): void
    {
        $pipeBackup = PipeBackup::findOrFail($pipeBackupId);
        $cards = $pipeBackup->backupCards();

        $pipeBackup->update([
            'cards_count' => (clone $cards)->whereIn('status', ['completed', 'completed_with_errors'])->count(),
            'attachments_count' => (clone $cards)->sum('attachments_count'),
            'errors_count' => (clone $cards)->sum('errors_count'),
        ]);

        $pendingOrProcessing = (clone $cards)->whereIn('status', ['pending', 'processing'])->count();

        if ($pendingOrProcessing === 0) {
            $hasFailed = (clone $cards)->where('status', 'failed')->exists();
            $pipeBackup->update([
                'status' => $hasFailed ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
            ]);

            $this->generateIndexJson($pipeBackup);
        }
    }

    private function generateIndexJson(PipeBackup $pipeBackup): void
    {
        try {
            $cards = $pipeBackup->backupCards()
                ->whereIn('status', ['completed', 'completed_with_errors'])
                ->get(['card_id', 'card_title']);

            $indexCards = $cards->map(fn (PipeBackupCard $card) => [
                'id' => $card->card_id,
                'title' => $card->card_title,
            ])->values()->toArray();

            $index = [
                'pipe_id' => $pipeBackup->pipe_id,
                'backup_date' => now()->toIso8601String(),
                'total_cards' => $pipeBackup->cards_count,
                'total_attachments_downloaded' => $pipeBackup->attachments_count,
                'errors_count' => $pipeBackup->errors_count,
                'cards' => $indexCards,
            ];

            Storage::disk('local')->put(
                "pipefy-backup/{$pipeBackup->pipe_id}/cards/index.json",
                json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );

            Log::info("index.json gerado para pipe {$pipeBackup->pipe_id} com ".count($indexCards).' cards.');
        } catch (\Throwable $e) {
            Log::warning("Falha ao gerar index.json para pipe {$pipeBackup->pipe_id}: {$e->getMessage()}");
        }
    }
}
