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

        if (preg_match('#uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/#i', $path, $matches)) {
            return $matches[1];
        }

        if (preg_match('#([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $path, $matches)) {
            return strtolower($matches[1]);
        }

        // Novo formato do storage-service: v1/resources/field/<id> onde o
        // último grupo pode ter menos de 12 chars (ex.: ...-6a63cec4d).
        if (preg_match('#resources/field/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]+)#i', $path, $matches)) {
            return strtolower($matches[1]);
        }

        // Fallback: qualquer segmento hex-com-dashes após field/ ou último
        // segmento da URL, para nunca quebrar o backup por formato novo.
        if (preg_match('#field/([0-9a-z\-]+)#i', $path, $matches)) {
            return strtolower(preg_replace('#[^0-9a-f\-]#i', '', $matches[1])) ?: md5($path);
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
        $name = ($attachment['filename'] ?? basename($attachment['path'] ?? '')) ?: 'unknown';

        $clean = preg_replace('#\p{C}+#u', '', $name);

        return ($clean !== '' && $clean !== null) ? $clean : 'unknown';
    }
}
