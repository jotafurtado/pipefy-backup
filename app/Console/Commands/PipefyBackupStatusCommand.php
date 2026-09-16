<?php

namespace App\Console\Commands;

use App\Models\PipeBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PipefyBackupStatusCommand extends Command
{
    protected $signature = 'pipefy:backup-status {--errors : Exibir erros detalhados}';

    protected $description = 'Exibe o status dos backups de pipes do Pipefy';

    public function handle(): int
    {
        $batchId = PipeBackup::latest()->value('batch_id');

        if (! $batchId) {
            $this->info('Nenhum registro de backup encontrado.');

            return self::SUCCESS;
        }

        $backups = PipeBackup::where('batch_id', $batchId)->get();

        $this->table(
            ['Pipe ID', 'Nome', 'Status', 'Cards', 'Attachments', 'Erros', 'Início', 'Conclusão'],
            $backups->map(fn (PipeBackup $backup) => [
                $backup->pipe_id,
                $backup->pipe_name,
                $backup->status,
                $backup->cards_count,
                $backup->attachments_count,
                $backup->errors_count,
                $backup->started_at?->format('Y-m-d H:i:s'),
                $backup->completed_at?->format('Y-m-d H:i:s'),
            ])->toArray(),
        );

        $total = $backups->count();
        $completed = $backups->where('status', 'completed')->count();
        $processing = $backups->where('status', 'processing')->count();
        $pending = $backups->where('status', 'pending')->count();
        $failed = $backups->where('status', 'failed')->count();
        $totalCards = $backups->sum('cards_count');
        $totalAttachments = $backups->sum('attachments_count');
        $totalErrors = $backups->sum('errors_count');

        $this->newLine();
        $this->info("Pipes: {$total} | Completados: {$completed} | Em processamento: {$processing} | Pendentes: {$pending} | Com falha: {$failed}");
        $this->info("Cards: {$totalCards} | Attachments: {$totalAttachments} | Erros: {$totalErrors}");

        if ($this->option('errors') && $totalErrors > 0) {
            $this->newLine();
            $this->info('Erros detalhados:');

            foreach ($backups as $backup) {
                $errors = $backup->errors;
                if ($errors->isEmpty()) {
                    continue;
                }

                $this->newLine();
                $this->comment("{$backup->pipe_name} (ID: {$backup->pipe_id}):");
                $this->table(
                    ['Tipo', 'Card ID', 'Arquivo', 'Mensagem'],
                    $errors->map(fn ($error) => [
                        $error->type,
                        $error->card_id ?? '-',
                        $error->filename ? Str::limit($error->filename, 40) : '-',
                        Str::limit($error->message, 60),
                    ])->toArray(),
                );
            }
        } elseif ($totalErrors > 0) {
            $this->comment('Use --errors para ver os erros detalhados.');
        }

        return self::SUCCESS;
    }
}
