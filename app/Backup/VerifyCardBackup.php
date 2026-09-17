<?php

namespace App\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VerifyCardBackup
{
    public function verify(int $pipeId, int $cardId, string $cardTitle): VerifyCardBackupResult
    {
        $issues = [];

        $jsonPath = BackupPaths::cardJson($pipeId, $cardId);

        if (! Storage::disk('local')->exists($jsonPath)) {
            $msg = "Verificação: Card JSON ausente - pipe:{$pipeId} card:{$cardId} ({$cardTitle})";
            Log::warning($msg);

            return new VerifyCardBackupResult(ok: false, issues: [$msg]);
        }

        $content = Storage::disk('local')->get($jsonPath);
        $cardData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($cardData)) {
            $msg = "Verificação: Card JSON inválido - pipe:{$pipeId} card:{$cardId} ({$cardTitle})";
            Log::warning($msg);

            return new VerifyCardBackupResult(ok: false, issues: [$msg]);
        }

        $attachments = $cardData['attachments'] ?? [];

        foreach ($attachments as $attachment) {
            $filename = BackupPaths::attachmentFilename($attachment);
            $attachmentPath = BackupPaths::attachment($pipeId, $cardId, $filename);

            if (! Storage::disk('local')->exists($attachmentPath)) {
                $msg = "Verificação: Attachment ausente - pipe:{$pipeId} card:{$cardId} file:{$filename}";
                Log::warning($msg);
                $issues[] = $msg;
            }
        }

        if (! empty($issues)) {
            return new VerifyCardBackupResult(ok: false, issues: $issues);
        }

        return new VerifyCardBackupResult(ok: true, issues: []);
    }
}
