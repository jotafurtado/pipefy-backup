<?php

namespace App\Jobs;

use App\Backup\VerifyCardBackup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VerifyCardBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $pipeId,
        public int $cardId,
        public string $cardTitle,
    ) {}

    /**
     * @return array{ok: bool, issues: list<string>}
     */
    public function handle(VerifyCardBackup $verifier): array
    {
        $result = $verifier->verify($this->pipeId, $this->cardId, $this->cardTitle);

        return ['ok' => $result->ok, 'issues' => $result->issues];
    }
}
