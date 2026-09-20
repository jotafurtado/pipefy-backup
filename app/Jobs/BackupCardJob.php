<?php

namespace App\Jobs;

use App\Backup\BackupPaths;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use App\Services\PipefyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

        // Detectar filenames duplicados: para esses cards, a migração do
        // path legado é insegura (não é possível mapear o arquivo legado ao
        // UUID correto do upload), então forçamos download fresco.
        $forceDownload = false;
        $filenames = array_map(
            fn ($a) => $a['filename'] ?? basename($a['path'] ?? ''),
            $attachments,
        );
        if (count($filenames) !== count(array_unique($filenames))) {
            $forceDownload = true;
        }

        // Baixar attachments
        $attachmentsDownloaded = 0;
        foreach ($attachments as $attachment) {
            try {
                $this->downloadAttachment($attachment, $this->cardId, $forceDownload);
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

    private function downloadAttachment(array $attachment, int $cardId, bool $forceDownload = false): void
    {
        $filename = BackupPaths::attachmentFilename($attachment);
        $pathUuid = BackupPaths::attachmentPathUuid($attachment);
        $storagePath = BackupPaths::attachment($this->pipeId, $cardId, $filename, $pathUuid);
        $fullPath = Storage::disk('local')->path($storagePath);
        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fileExists = Storage::disk('local')->exists($storagePath) && is_file($fullPath);
        $legacyPath = "pipefy-backup/{$this->pipeId}/attachments/{$cardId}/{$filename}";
        $legacyExists = ! $forceDownload && Storage::disk('local')->exists($legacyPath);

        $contentLength = 0;

        // Se o arquivo já existe no destino ou no path legado, consulta HEAD para checar integridade do tamanho.
        // Se o arquivo NÃO existe, pula o HEAD para economizar 50% das requisições contra o storage do Pipefy.
        if ($fileExists || $legacyExists) {
            $contentLength = $this->fetchContentLength($attachment['url']);

            if ($contentLength > 0 && $fileExists && filesize($fullPath) === $contentLength) {
                $this->persistContentLength($attachment, $contentLength);

                return;
            }

            if ($contentLength > 0 && $legacyExists) {
                $legacyFull = Storage::disk('local')->path($legacyPath);

                if (@rename($legacyFull, $fullPath)) {
                    $this->persistContentLength($attachment, $contentLength);

                    return;
                }
            }
        }

        $this->performDownload($attachment, $fullPath, $contentLength);
    }

    private function fetchContentLength(string $url): int
    {
        $delays = app()->runningUnitTests() ? [0, 0] : [500, 1000];

        try {
            $response = Http::connectTimeout(15)
                ->timeout(30)
                ->retry($delays, 0, function (\Throwable $exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof RequestException) {
                        $status = $exception->response->status();

                        return $status === 429 || $exception->response->serverError();
                    }

                    return false;
                })
                ->head($url);

            if ($response->successful()) {
                return (int) $response->header('Content-Length');
            }
        } catch (\Throwable) {
            // Falhas transitórias no HEAD não devem bloquear o download via GET
        }

        return 0;
    }

    private function performDownload(array $attachment, string $fullPath, int $contentLength): void
    {
        $tempPath = $fullPath.'.tmp';
        $delays = app()->runningUnitTests() ? [0, 0, 0] : [1000, 2000, 5000];

        try {
            $response = Http::connectTimeout(20)
                ->timeout(180)
                ->retry($delays, 0, function (\Throwable $exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof RequestException) {
                        $status = $exception->response->status();

                        return $status === 429 || $exception->response->serverError();
                    }

                    return false;
                })
                ->withOptions(['sink' => $tempPath])
                ->get($attachment['url']);

            if ($response->failed()) {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }

                throw new \RuntimeException("Download falhou: HTTP {$response->status()}");
            }

            if (file_exists($tempPath)) {
                rename($tempPath, $fullPath);
            }
        } catch (\Throwable $e) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            throw $e;
        }

        if ($contentLength <= 0) {
            $contentLength = (int) $response->header('Content-Length');
            if ($contentLength <= 0 && is_file($fullPath)) {
                $contentLength = (int) filesize($fullPath);
            }
        }

        $this->persistContentLength($attachment, $contentLength);
    }

    private function persistContentLength(array $attachment, int $contentLength): void
    {
        if ($contentLength <= 0) {
            return;
        }

        $cardJsonPath = BackupPaths::cardJson($this->pipeId, $this->cardId);
        $raw = Storage::disk('local')->get($cardJsonPath);

        if ($raw === null) {
            return;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['attachments']) || ! is_array($data['attachments'])) {
            return;
        }

        $targetPath = $attachment['path'] ?? null;

        if ($targetPath === null) {
            return;
        }

        foreach ($data['attachments'] as $i => $entry) {
            if ((is_array($entry) ? ($entry['path'] ?? null) : null) === $targetPath) {
                $data['attachments'][$i]['content_length'] = $contentLength;
                break;
            }
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return;
        }

        Storage::disk('local')->put($cardJsonPath, $encoded);
    }
}
