<?php

namespace App\Console\Commands;

use App\Backup\RetryFailedCards;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Services\PipefyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PipefyBackupAllCommand extends Command
{
    protected $signature = 'pipefy:backup-all {--retry : Reprocessar pipes com falha do último batch}';

    protected $description = 'Faz backup de todos os pipes da organização no Pipefy';

    public function handle(PipefyService $pipefy, RetryFailedCards $retryFailedCards): int
    {
        if ($this->option('retry')) {
            return $this->handleRetry($retryFailedCards);
        }

        $organizationId = config('services.pipefy.organization_id');

        if (empty($organizationId)) {
            $this->error('A configuração services.pipefy.organization_id não está definida.');

            return self::FAILURE;
        }

        try {
            $pipes = $pipefy->getPipes((int) $organizationId);
        } catch (\Throwable $e) {
            $this->error('Erro ao consultar pipes da organização: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($pipes)) {
            $this->info('Nenhum pipe encontrado na organização.');

            return self::SUCCESS;
        }

        return $this->handleQueue($pipes);
    }

    /**
     * @param  array<int, array{id: int, name: string}>  $pipes
     */
    private function handleQueue(array $pipes): int
    {
        $batchId = Str::uuid()->toString();

        foreach ($pipes as $pipe) {
            $pipeBackup = PipeBackup::create([
                'batch_id' => $batchId,
                'pipe_id' => $pipe['id'],
                'pipe_name' => $pipe['name'],
                'status' => 'pending',
            ]);

            BackupPipeJob::dispatch($pipeBackup->id, $pipe['id']);
        }

        $this->table(
            ['Pipe ID', 'Nome'],
            array_map(fn (array $pipe) => [$pipe['id'], $pipe['name']], $pipes),
        );

        $this->info('Total de jobs despachados: '.count($pipes));
        $this->info('Execute `php artisan pipefy:backup-status` para acompanhar o progresso.');

        return self::SUCCESS;
    }

    private function handleRetry(RetryFailedCards $retryFailedCards): int
    {
        $batchId = PipeBackup::latest()->value('batch_id');

        if (! $batchId) {
            $this->info('Nenhum batch encontrado.');

            return self::SUCCESS;
        }

        $report = $retryFailedCards->retry($batchId);

        if ($report->isEmpty()) {
            $this->info('Nenhum card com falha para reprocessar.');

            return self::SUCCESS;
        }

        $rows = array_map(
            fn ($item) => [$item->pipeId, $item->pipeName, $item->cardId, $item->cardTitle],
            $report->items,
        );

        $this->table(['Pipe ID', 'Pipe', 'Card ID', 'Card'], $rows);
        $this->info("Total de cards redespachados: {$report->count}");

        return self::SUCCESS;
    }
}
