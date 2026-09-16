<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VerifyCardBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $pipeId,
        public int $cardId,
        public string $cardTitle,
    ) {}

    /**
     * @return array{ok: bool, issues: list<string>}
     */
    public function handle(): array
    {
        $issues = [];

        // Verificar existência do JSON do card
        $jsonPath = "pipefy-backup/{$this->pipeId}/cards/{$this->cardId}.json";

        if (! Storage::disk('local')->exists($jsonPath)) {
            $msg = "Verificação: Card JSON ausente - pipe:{$this->pipeId} card:{$this->cardId} ({$this->cardTitle})";
            Log::warning($msg);

            return ['ok' => false, 'issues' => [$msg]];
        }

        // Verificar validade do JSON
        $content = Storage::disk('local')->get($jsonPath);
        $cardData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($cardData)) {
            $msg = "Verificação: Card JSON inválido - pipe:{$this->pipeId} card:{$this->cardId} ({$this->cardTitle})";
            Log::warning($msg);

            return ['ok' => false, 'issues' => [$msg]];
        }

        // Verificar attachments referenciados no JSON
        $attachments = $cardData['attachments'] ?? [];

        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'] ?? basename($attachment['path'] ?? '');
            $attachmentPath = "pipefy-backup/{$this->pipeId}/attachments/{$this->cardId}/{$filename}";

            if (! Storage::disk('local')->exists($attachmentPath)) {
                $msg = "Verificação: Attachment ausente - pipe:{$this->pipeId} card:{$this->cardId} file:{$filename}";
                Log::warning($msg);
                $issues[] = $msg;
            }
        }

        if (! empty($issues)) {
            return ['ok' => false, 'issues' => $issues];
        }

        return ['ok' => true, 'issues' => []];
    }
}
