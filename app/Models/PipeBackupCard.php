<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipeBackupCard extends Model
{
    /** @use HasFactory<\Database\Factories\PipeBackupCardFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pipe_backup_id',
        'card_id',
        'card_title',
        'status',
        'attachments_count',
        'errors_count',
        'error_message',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'card_id' => 'integer',
            'attachments_count' => 'integer',
            'errors_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<PipeBackupError, $this>
     */
    public function pipeBackupErrors(): HasMany
    {
        return $this->hasMany(PipeBackupError::class);
    }
}
