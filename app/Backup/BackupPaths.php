<?php

namespace App\Backup;

class BackupPaths
{
    public static function cardJson(int $pipeId, int $cardId): string
    {
        return "pipefy-backup/{$pipeId}/cards/{$cardId}.json";
    }

    public static function attachment(int $pipeId, int $cardId, string $filename): string
    {
        return "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$filename}";
    }

    public static function index(int $pipeId): string
    {
        return "pipefy-backup/{$pipeId}/cards/index.json";
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    public static function attachmentFilename(array $attachment): string
    {
        return ($attachment['filename'] ?? basename($attachment['path'] ?? '')) ?: 'unknown';
    }
}
