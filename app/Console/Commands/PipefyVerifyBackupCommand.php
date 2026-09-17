<?php

namespace App\Console\Commands;

use App\Backup\BackupPaths;
use App\Backup\VerifyCardBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PipefyVerifyBackupCommand extends Command
{
    protected $signature = 'pipefy:verify-backup {pipe_id? : ID do pipe para verificar} {--all : Verificar todos os pipes com backup em disco}';

    protected $description = 'Verifica a integridade dos arquivos de backup de um ou todos os pipes';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->verifyAll();
        }

        $pipeId = $this->argument('pipe_id');

        if (! $pipeId) {
            $this->error('Informe um pipe_id ou use --all para verificar todos.');

            return self::FAILURE;
        }

        return $this->verifyPipe((int) $pipeId);
    }

    private function verifyAll(): int
    {
        $basePath = 'pipefy-backup';

        if (! Storage::disk('local')->exists($basePath)) {
            $this->error('Nenhum diretório de backup encontrado.');

            return self::FAILURE;
        }

        $directories = Storage::disk('local')->directories($basePath);
        $pipeIds = collect($directories)
            ->map(fn (string $dir) => basename($dir))
            ->filter(fn (string $name) => is_numeric($name))
            ->map(fn (string $name) => (int) $name)
            ->sort()
            ->values();

        if ($pipeIds->isEmpty()) {
            $this->info('Nenhum pipe com backup encontrado em disco.');

            return self::SUCCESS;
        }

        $this->info("Verificando {$pipeIds->count()} pipes...");
        $this->newLine();

        $hasFailures = false;

        foreach ($pipeIds as $pipeId) {
            $result = $this->verifyPipe($pipeId);

            if ($result === self::FAILURE) {
                $hasFailures = true;
            }

            $this->newLine();
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    private function verifyPipe(int $pipeId): int
    {
        $indexPath = BackupPaths::index($pipeId);

        if (! Storage::disk('local')->exists($indexPath)) {
            $this->error("Arquivo index.json não encontrado para o pipe {$pipeId}.");

            return self::FAILURE;
        }

        $content = Storage::disk('local')->get($indexPath);
        $index = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($index)) {
            $this->error("Arquivo index.json inválido para o pipe {$pipeId}.");

            return self::FAILURE;
        }

        $cards = $index['cards'] ?? [];

        if (empty($cards)) {
            $this->info("Nenhum card encontrado no index.json do pipe {$pipeId}.");

            return self::SUCCESS;
        }

        $totalCards = count($cards);
        $cardsOk = 0;
        $cardsWithIssues = 0;
        $allIssues = [];

        foreach ($cards as $card) {
            $cardId = (int) ($card['id'] ?? 0);
            $cardTitle = $card['title'] ?? '';

            $result = app(VerifyCardBackup::class)->verify($pipeId, $cardId, $cardTitle);

            if ($result->ok) {
                $cardsOk++;
            } else {
                $cardsWithIssues++;
                $allIssues = array_merge($allIssues, $result->issues);
            }
        }

        $this->info("Pipe {$pipeId}: {$totalCards} cards | {$cardsOk} íntegros | {$cardsWithIssues} com problemas");

        if (! empty($allIssues)) {
            $this->warn('  Problemas:');

            foreach ($allIssues as $issue) {
                $this->line("    - {$issue}");
            }
        }

        return $cardsWithIssues > 0 ? self::FAILURE : self::SUCCESS;
    }
}
