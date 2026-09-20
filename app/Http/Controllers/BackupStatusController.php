<?php

namespace App\Http\Controllers;

use App\Backup\RetryFailedCards;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;
use App\Models\PipeBackupError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\View\View;

class BackupStatusController extends Controller
{
    public function index(): View
    {
        return view('backup-status', $this->buildStatusData());
    }

    public function status(): JsonResponse
    {
        return response()->json($this->buildStatusData());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusData(): array
    {
        $batchId = PipeBackup::latest()->value('batch_id');
        $backups = $batchId
            ? PipeBackup::where('batch_id', $batchId)->with('backupCards')->orderBy('pipe_name')->get()
            : collect();

        $backupsData = $backups->map(function (PipeBackup $backup) {
            $cardStatusCounts = $backup->backupCards
                ->groupBy('status')
                ->map->count();

            $cardSummary = [
                'pending' => $cardStatusCounts->get('pending', 0),
                'processing' => $cardStatusCounts->get('processing', 0),
                'completed' => $cardStatusCounts->get('completed', 0),
                'completed_with_errors' => $cardStatusCounts->get('completed_with_errors', 0),
                'failed' => $cardStatusCounts->get('failed', 0),
            ];

            $done = $cardSummary['completed'] + $cardSummary['completed_with_errors'];
            $total = max($backup->backupCards->count(), (int) ($backup->total_cards ?? 0));

            return [
                ...$backup->toArray(),
                'card_status_summary' => $cardSummary,
                'cards_done' => $done,
                'cards_total' => $total,
                'progress_percent' => $total > 0
                    ? (int) min(100, round(($done / $total) * 100))
                    : ($backup->status === 'completed' ? 100 : 0),
            ];
        });

        $cardTotals = [
            'pending' => $backupsData->sum('card_status_summary.pending'),
            'processing' => $backupsData->sum('card_status_summary.processing'),
            'completed' => $backupsData->sum('card_status_summary.completed'),
            'completed_with_errors' => $backupsData->sum('card_status_summary.completed_with_errors'),
            'failed' => $backupsData->sum('card_status_summary.failed'),
        ];
        $cardsDone = $cardTotals['completed'] + $cardTotals['completed_with_errors'];
        $cardsTotal = array_sum($cardTotals);
        $cardsRemaining = $cardTotals['pending'] + $cardTotals['processing'];

        $throughputPerMin = $this->cardThroughputPerMinute($backups->pluck('id'));

        $queueConnection = (string) config('queue.default');
        $queueName = (string) config('queue.connections.'.$queueConnection.'.queue', 'default');

        return [
            'batch_id' => $batchId,
            'backups' => $backupsData,
            'summary' => [
                'total' => $backups->count(),
                'completed' => $backups->where('status', 'completed')->count(),
                'processing' => $backups->where('status', 'processing')->count(),
                'pending' => $backups->where('status', 'pending')->count(),
                'failed' => $backups->where('status', 'failed')->count(),
                'completed_with_errors' => $backups->where('status', 'completed_with_errors')->count(),
            ],
            'batch' => [
                'id' => $batchId,
                'started_at' => optional($backups->min('started_at'))->toIso8601String(),
                'updated_at' => optional($backups->max('updated_at'))->toIso8601String(),
            ],
            'queue' => [
                'connection' => $queueConnection,
                'name' => $queueName,
                'pending' => $this->queuePendingSize($queueName),
                'failed' => $this->failedJobsCount(),
            ],
            'cards' => [
                ...$cardTotals,
                'done' => $cardsDone,
                'total' => $cardsTotal,
                'remaining' => $cardsRemaining,
                'percent' => $cardsTotal > 0 ? (int) min(100, round(($cardsDone / $cardsTotal) * 100)) : 0,
                'throughput_per_min' => $throughputPerMin,
                'eta_seconds' => $throughputPerMin > 0 && $cardsRemaining > 0
                    ? (int) round($cardsRemaining / $throughputPerMin * 60)
                    : null,
                'attachments_total' => $backups->sum('attachments_count'),
                'errors_total' => $backups->sum('errors_count'),
            ],
            'errors_recent' => $this->recentErrors($backups->pluck('id')),
        ];
    }

    private function queuePendingSize(string $queueName): ?int
    {
        try {
            return Queue::connection()->size($queueName);
        } catch (\Throwable) {
            return null;
        }
    }

    private function failedJobsCount(): ?int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  Collection<int, int>  $pipeBackupIds
     */
    private function cardThroughputPerMinute($pipeBackupIds): float
    {
        if ($pipeBackupIds->isEmpty()) {
            return 0.0;
        }

        try {
            $recent = PipeBackupCard::whereIn('pipe_backup_id', $pipeBackupIds)
                ->whereIn('status', ['completed', 'completed_with_errors'])
                ->where('completed_at', '>=', now()->subMinutes(5))
                ->count();

            return round($recent / 5, 1);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * @param  Collection<int, int>  $pipeBackupIds
     * @return list<array<string, mixed>>
     */
    private function recentErrors($pipeBackupIds): array
    {
        if ($pipeBackupIds->isEmpty()) {
            return [];
        }

        try {
            return PipeBackupError::whereIn('pipe_backup_id', $pipeBackupIds)
                ->with('pipeBackup:id,pipe_name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (PipeBackupError $error) => [
                    'pipe_name' => $error->pipeBackup->pipe_name ?? ('#'.$error->pipe_backup_id),
                    'card_id' => $error->card_id,
                    'type' => $error->type,
                    'filename' => $error->filename,
                    'message' => $error->message,
                    'created_at' => optional($error->created_at)->toIso8601String(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function retry(Request $request, RetryFailedCards $retryFailedCards): JsonResponse
    {
        $batchId = PipeBackup::latest()->value('batch_id');

        if (! $batchId) {
            return response()->json(['message' => 'Nenhum batch encontrado.'], 404);
        }

        // scope: failed (padrão) | errored (parciais) | all (ambos)
        $scope = $request->input('scope', 'failed');
        $retryFailed = $scope === 'all' || $scope !== 'errored';
        $retryErrored = $scope === 'all' || $scope === 'errored';

        $totalRetried = 0;
        $failedCount = 0;
        $erroredCount = 0;

        // Retry pipes that failed at the pipe level (no cards fetched)
        if ($retryFailed) {
            $failedPipes = PipeBackup::where('batch_id', $batchId)
                ->where('status', 'failed')
                ->get();

            foreach ($failedPipes as $backup) {
                // Limpar cards parciais caso o pipe tenha falhado no meio da busca
                $backup->backupCards()->delete();

                $backup->update([
                    'status' => 'pending',
                    'error_message' => null,
                    'total_cards' => 0,
                    'current_step' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

                BackupPipeJob::dispatch($backup->id, $backup->pipe_id);
                $failedCount++;
            }

            $cardReport = $retryFailedCards->retry($batchId);
            $failedCount += $cardReport->count;
        }

        if ($retryErrored) {
            $erroredCount = $retryFailedCards->retryErroredCards($batchId)->count;
        }

        $totalRetried = $failedCount + $erroredCount;

        if ($totalRetried === 0) {
            return response()->json(['message' => 'Nenhum item com falha para reprocessar.']);
        }

        return response()->json([
            'message' => "Redespachados {$totalRetried} itens para reprocessamento.",
            'count' => $totalRetried,
            'failed' => $failedCount,
            'errored' => $erroredCount,
        ]);
    }
}
