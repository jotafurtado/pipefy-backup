<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipeBackupError extends Model
{
    protected $fillable = [
        'pipe_backup_id',
        'pipe_backup_card_id',
        'type',
        'card_id',
        'filename',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'card_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PipeBackup, $this>
     */
    public function pipeBackup(): BelongsTo
    {
        return $this->belongsTo(PipeBackup::class);
    }

    /**
     * @return BelongsTo<PipeBackupCard, $this>
     */
    public function pipeBackupCard(): BelongsTo
    {
        return $this->belongsTo(PipeBackupCard::class);
    }
}
