<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BackupJob extends Model
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_RESTORE = 'restore';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'database_instance_id',
        'type',
        'status',
        'progress',
        'current_step',
        'size_bytes',
        's3_key',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress' => 'integer',
        'size_bytes' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($job) {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function databaseInstance(): BelongsTo
    {
        return $this->belongsTo(DatabaseInstance::class);
    }

    // ==================== STATUS HELPERS ====================

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    // ==================== PROGRESS UPDATES ====================

    public function markAsRunning(): self
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'progress' => 0,
        ]);
        return $this;
    }

    public function updateProgress(int $progress, string $step = null): self
    {
        $this->update([
            'progress' => min(100, max(0, $progress)),
            'current_step' => $step,
        ]);
        return $this;
    }

    public function markAsCompleted(string $s3Key, int $sizeBytes): self
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'progress' => 100,
            'current_step' => 'Concluído',
            's3_key' => $s3Key,
            'size_bytes' => $sizeBytes,
            'completed_at' => now(),
        ]);
        return $this;
    }

    public function markAsFailed(string $errorMessage): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'current_step' => 'Falhou',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
        return $this;
    }

    // ==================== SCOPES ====================

    public function scopeForDatabase($query, int $databaseId)
    {
        return $query->where('database_instance_id', $databaseId);
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    // ==================== FORMATTERS ====================

    public function getFormattedSize(): string
    {
        if (!$this->size_bytes) return '-';
        
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getDuration(): ?string
    {
        if (!$this->started_at || !$this->completed_at) return null;
        
        $seconds = $this->completed_at->diffInSeconds($this->started_at);
        if ($seconds < 60) return "{$seconds}s";
        
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return "{$minutes}m {$secs}s";
    }
}

