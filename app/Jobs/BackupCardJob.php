<?php

namespace App\Jobs;

use App\Backup\BackupPaths;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use App\Services\PipefyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
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
        $cardJsonPath = BackupPaths::cardJson($this->pipeId, $this->cardId);
        $cardJson = Storage::disk('local')->get($cardJsonPath);

        if (! $cardJson) {
            $backupCard->update([
                'status' => 'failed',
                'error_message' => "Card JSON não encontrado: {$cardJsonPath}",
                'completed_at' => now(),
            ]);
            $this->notifyCardCompletion($backupCard);

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
            $this->notifyCardCompletion($backupCard);

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

        $this->notifyCardCompletion($backupCard);
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
            $this->notifyCardCompletion($backupCard);
        }
    }

    private function notifyCardCompletion(PipeBackupCard $backupCard): void
    {
        $backupCard->pipeBackup->recalculateStatus();
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
        $filename = BackupPaths::attachmentFilename($attachment);
        $pathUuid = BackupPaths::attachmentPathUuid($attachment);
        $storagePath = BackupPaths::attachment($this->pipeId, $cardId, $filename, $pathUuid);
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
}
