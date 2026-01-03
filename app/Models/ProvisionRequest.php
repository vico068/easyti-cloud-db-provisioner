<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProvisionRequest extends Model
{
    use HasFactory;

    // Status
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Tipos de serviço
    public const TYPE_DB_RELACIONAL = 'DB_RELACIONAL';
    public const TYPE_DB_NAO_RELACIONAL = 'DB_NAO_RELACIONAL';

    protected $fillable = [
        'uuid',
        'service_type',
        'engine',
        'config',
        'password_encrypted', // Senha definida pelo usuário (criptografada)
        'status',
        'database_instance_id',
        'error_message',
        'external_user_id',
        'external_slot_id',
        'callback_url',
        'metadata',
        'started_at',
        'completed_at',
    ];

    /**
     * Retorna a senha descriptografada
     */
    public function getPassword(): ?string
    {
        if (empty($this->password_encrypted)) {
            return null;
        }
        
        try {
            return decrypt($this->password_encrypted);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected $casts = [
        'config' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Relacionamento com instância
     */
    public function databaseInstance()
    {
        return $this->belongsTo(DatabaseInstance::class);
    }

    /**
     * Marca como processando
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Marca como completo
     */
    public function markAsCompleted(DatabaseInstance $instance): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'database_instance_id' => $instance->id,
            'completed_at' => now(),
        ]);
    }

    /**
     * Marca como falho
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}

