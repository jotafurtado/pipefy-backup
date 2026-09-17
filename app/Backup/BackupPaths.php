<?php

namespace App\Backup;

class BackupPaths
{
    public static function cardJson(int $pipeId, int $cardId): string
    {
        return "pipefy-backup/{$pipeId}/cards/{$cardId}.json";
    }

    public static function attachment(int $pipeId, int $cardId, string $filename, string $pathUuid): string
    {
        return "pipefy-backup/{$pipeId}/attachments/{$cardId}/{$pathUuid}/{$filename}";
    }

    /**
     * @param  array<string, mixed>  $attachment
     *
     * @throws \InvalidArgumentException
     */
    public static function attachmentPathUuid(array $attachment): string
    {
        $path = $attachment['path'] ?? '';

        if (preg_match('#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/#i', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException("Cannot extract upload UUID from attachment path: {$path}");
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
