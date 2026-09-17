<?php

namespace App\Backup;

use App\Jobs\BackupCardJob;
use App\Models\PipeBackup;
use App\Models\PipeBackupCard;

class RetryFailedCards
{
    public function retry(string $batchId): RetryFailedCardsReport
    {
        $backupsWithFailedCards = PipeBackup::where('batch_id', $batchId)
            ->whereHas('backupCards', fn ($q) => $q->where('status', 'failed'))
            ->get();

        $items = [];

        foreach ($backupsWithFailedCards as $backup) {
            $failedCards = $backup->backupCards()->where('status', 'failed')->get();

            foreach ($failedCards as $card) {
                $this->resetCard($card);

                BackupCardJob::dispatch(
                    $card->id,
                    $backup->pipe_id,
                    $card->card_id,
                );

                $items[] = new RetryFailedCardItem(
                    pipeId: $backup->pipe_id,
                    pipeName: $backup->pipe_name,
                    cardId: $card->card_id,
                    cardTitle: $card->card_title,
                );
            }

            $backup->update(['status' => 'processing']);
        }

        return new RetryFailedCardsReport(count: count($items), items: $items);
    }

    private function resetCard(PipeBackupCard $card): void
    {
        $card->update([
            'status' => 'pending',
            'error_message' => null,
            'errors_count' => 0,
            'started_at' => null,
            'completed_at' => null,
        ]);
    }
}
