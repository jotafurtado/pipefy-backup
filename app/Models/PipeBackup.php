<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipeBackup extends Model
{
    /** @use HasFactory<\Database\Factories\PipeBackupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'batch_id',
        'pipe_id',
        'pipe_name',
        'cards_count',
        'attachments_count',
        'errors_count',
        'total_cards',
        'current_step',
        'status',
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
            'pipe_id' => 'integer',
            'cards_count' => 'integer',
            'attachments_count' => 'integer',
            'errors_count' => 'integer',
            'total_cards' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PipeBackupError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(PipeBackupError::class);
    }

    /**
     * @return HasMany<PipeBackupCard, $this>
     */
    public function backupCards(): HasMany
    {
        return $this->hasMany(PipeBackupCard::class);
    }
}
