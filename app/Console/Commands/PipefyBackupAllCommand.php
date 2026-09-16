<?php

namespace App\Console\Commands;

use App\Jobs\BackupCardJob;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Services\PipefyService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PipefyBackupAllCommand extends Command
{
    protected $signature = 'pipefy:backup-all {--retry : Reprocessar pipes com falha do último batch}';

    protected $description = 'Faz backup de todos os pipes da organização no Pipefy';

    public function handle(PipefyService $pipefy): int
    {
        if ($this->option('retry')) {
            return $this->handleRetry();
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

    private function handleRetry(): int
    {
        $batchId = PipeBackup::latest()->value('batch_id');

        if (! $batchId) {
            $this->info('Nenhum batch encontrado.');

            return self::SUCCESS;
        }

        $backupsWithFailedCards = PipeBackup::where('batch_id', $batchId)
            ->whereHas('backupCards', fn ($q) => $q->where('status', 'failed'))
            ->get();

        if ($backupsWithFailedCards->isEmpty()) {
            $this->info('Nenhum card com falha para reprocessar.');

            return self::SUCCESS;
        }

        $totalRetried = 0;
        $rows = [];

        foreach ($backupsWithFailedCards as $backup) {
            $failedCards = $backup->backupCards()->where('status', 'failed')->get();

            foreach ($failedCards as $card) {
                $card->update([
                    'status' => 'pending',
                    'error_message' => null,
                    'errors_count' => 0,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

                BackupCardJob::dispatch(
                    $card->id,
                    $backup->pipe_id,
                    $card->card_id,
                );

                $rows[] = [$backup->pipe_id, $backup->pipe_name, $card->card_id, $card->card_title];
                $totalRetried++;
            }

            $backup->update(['status' => 'processing']);
        }

        $this->table(['Pipe ID', 'Pipe', 'Card ID', 'Card'], $rows);
        $this->info("Total de cards redespachados: {$totalRetried}");

        return self::SUCCESS;
    }
}
