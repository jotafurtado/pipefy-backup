<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAttachmentsCommand extends Command
{
    protected $signature = 'pipefy:cleanup-attachments
                            {--dry-run : Apenas listar os arquivos órfãos sem remover}';

    protected $description = 'Remove arquivos de attachment órfãos do esquema de path legado (diretamente em attachments/{cardId}/)';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $basePath = 'pipefy-backup';

        if (! $disk->exists($basePath)) {
            $this->info('Nenhum backup encontrado em disco.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $orphans = $this->findOrphans($disk, $basePath);

        if ($orphans === []) {
            $this->info('Nenhum arquivo órfão encontrado.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('%d arquivo(s) órfão(s) encontrado(s) (dry-run):', count($orphans)));
            foreach ($orphans as $path) {
                $this->line("  {$path}");
            }

            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($orphans as $path) {
            if ($disk->delete($path)) {
                $removed++;
            }
        }

        $this->info(sprintf('%d arquivo(s) órfão(s) removido(s).', $removed));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function findOrphans($disk, string $basePath): array
    {
        $orphans = [];

        $pipeDirs = $disk->directories($basePath);

        foreach ($pipeDirs as $pipeDir) {
            $attachmentsDir = rtrim($pipeDir, '/').'/attachments';

            if (! $disk->exists($attachmentsDir)) {
                continue;
            }

            $cardDirs = $disk->directories($attachmentsDir);

            foreach ($cardDirs as $cardDir) {
                $files = $disk->files($cardDir);

                foreach ($files as $file) {
                    $orphans[] = $file;
                }
            }
        }

        return $orphans;
    }
}
