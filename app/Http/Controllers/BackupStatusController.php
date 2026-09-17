<?php

namespace App\Http\Controllers;

use App\Backup\RetryFailedCards;
use App\Jobs\BackupPipeJob;
use App\Models\PipeBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class BackupStatusController extends Controller
{
    public function index(): View
    {
        $batchId = PipeBackup::latest()->value('batch_id');
        $backups = $batchId ? PipeBackup::where('batch_id', $batchId)->with('backupCards')->get() : collect();

        return view('backup-status', compact('backups', 'batchId'));
    }

    public function status(): JsonResponse
    {
        $batchId = PipeBackup::latest()->value('batch_id');
        $backups = $batchId
            ? PipeBackup::where('batch_id', $batchId)->with('backupCards')->get()
            : collect();

        $backupsData = $backups->map(function (PipeBackup $backup) {
            $cardStatusCounts = $backup->backupCards
                ->groupBy('status')
                ->map->count();

            return [
                ...$backup->toArray(),
                'card_status_summary' => [
                    'pending' => $cardStatusCounts->get('pending', 0),
                    'processing' => $cardStatusCounts->get('processing', 0),
                    'completed' => $cardStatusCounts->get('completed', 0),
                    'completed_with_errors' => $cardStatusCounts->get('completed_with_errors', 0),
                    'failed' => $cardStatusCounts->get('failed', 0),
                ],
            ];
        });

        return response()->json([
            'batch_id' => $batchId,
            'backups' => $backupsData,
            'summary' => [
                'total' => $backups->count(),
                'completed' => $backups->where('status', 'completed')->count(),
                'processing' => $backups->where('status', 'processing')->count(),
                'pending' => $backups->where('status', 'pending')->count(),
                'failed' => $backups->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function retry(RetryFailedCards $retryFailedCards): JsonResponse
    {
        $batchId = PipeBackup::latest()->value('batch_id');

        if (! $batchId) {
            return response()->json(['message' => 'Nenhum batch encontrado.'], 404);
        }

        $totalRetried = 0;

        // Retry pipes that failed at the pipe level (no cards fetched)
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
            $totalRetried++;
        }

        $cardReport = $retryFailedCards->retry($batchId);
        $totalRetried += $cardReport->count;

        if ($totalRetried === 0) {
            return response()->json(['message' => 'Nenhum item com falha para reprocessar.']);
        }

        return response()->json([
            'message' => "Redespachados {$totalRetried} itens para reprocessamento.",
            'count' => $totalRetried,
        ]);
    }
}
