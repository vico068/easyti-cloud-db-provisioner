<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class DatabaseInstance extends Model
{
    use HasFactory, SoftDeletes;

    // Engines suportadas
    public const ENGINE_POSTGRES = 'postgres';
    public const ENGINE_MYSQL = 'mysql';
    public const ENGINE_REDIS = 'redis';

    // Status
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'uuid',
        'engine',
        'vcpu',
        'ram_mb',
        'disk_gb',
        'host',
        'port',
        'database_name',
        'username',
        'password_encrypted',
        'container_id',
        'container_name',
        'volume_name',
        'status',
        'error_message',
        'external_user_id',
        'external_slot_id',
        'external_request_id',
        'metadata',
        'provisioned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'provisioned_at' => 'datetime',
        'vcpu' => 'integer',
        'ram_mb' => 'integer',
        'disk_gb' => 'integer',
        'port' => 'integer',
    ];

    protected $hidden = [
        'password_encrypted',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($instance) {
            if (empty($instance->uuid)) {
                $instance->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Define a senha (criptografada)
     */
    public function setPasswordAttribute(string $password): void
    {
        $this->attributes['password_encrypted'] = Crypt::encryptString($password);
    }

    /**
     * Obtém a senha descriptografada
     */
    public function getPasswordAttribute(): ?string
    {
        if (empty($this->password_encrypted)) {
            return null;
        }
        return Crypt::decryptString($this->password_encrypted);
    }

    /**
     * Verifica se é um banco relacional
     */
    public function isRelational(): bool
    {
        return in_array($this->engine, [self::ENGINE_POSTGRES, self::ENGINE_MYSQL]);
    }

    /**
     * Verifica se está em execução
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Obtém string de conexão
     */
    public function getConnectionString(): string
    {
        switch ($this->engine) {
            case self::ENGINE_POSTGRES:
                return "postgresql://{$this->username}:{$this->password}@{$this->host}:{$this->port}/{$this->database_name}";
            case self::ENGINE_MYSQL:
                return "mysql://{$this->username}:{$this->password}@{$this->host}:{$this->port}/{$this->database_name}";
            case self::ENGINE_REDIS:
                return "redis://:{$this->password}@{$this->host}:{$this->port}";
            default:
                return '';
        }
    }

    /**
     * Obtém as credenciais para retorno ao usuário
     */
    public function getCredentials(): array
    {
        $credentials = [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
        ];

        if ($this->isRelational()) {
            $credentials['database'] = $this->database_name;
        }

        return $credentials;
    }

    /**
     * Relacionamento com requests
     */
    public function provisionRequests()
    {
        return $this->hasMany(ProvisionRequest::class);
    }
}

