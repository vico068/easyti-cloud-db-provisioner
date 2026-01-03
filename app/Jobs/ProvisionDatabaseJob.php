<?php

namespace App\Jobs;

use App\Models\DatabaseInstance;
use App\Models\ProvisionRequest;
use App\Services\DockerService;
use App\Services\HostGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisionDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutos

    public function __construct(
        public ProvisionRequest $request
    ) {
    }

    public function handle(DockerService $dockerService, HostGeneratorService $hostGenerator): void
    {
        Log::info("Iniciando provisionamento de banco de dados", [
            'request_id' => $this->request->id,
            'engine' => $this->request->engine,
        ]);

        // Verifica se já existe uma instância para este request (evita duplicatas)
        $existingInstance = DatabaseInstance::where('external_request_id', $this->request->uuid)->first();
        
        if ($existingInstance) {
            // Se já existe e está em estado final, não faz nada
            if (in_array($existingInstance->status, [
                DatabaseInstance::STATUS_RUNNING,
                DatabaseInstance::STATUS_STOPPED,
            ])) {
                Log::info("Instância já existe e está provisionada", [
                    'request_id' => $this->request->uuid,
                    'instance_id' => $existingInstance->id,
                ]);
                return;
            }
            
            // Se está provisionando, usa a instância existente
            if ($existingInstance->status === DatabaseInstance::STATUS_PROVISIONING) {
                Log::info("Usando instância existente em provisionamento", [
                    'request_id' => $this->request->uuid,
                    'instance_id' => $existingInstance->id,
                ]);
                $instance = $existingInstance;
            }
        }

        $this->request->markAsProcessing();

        try {
            // Só cria nova instância se não existir
            $instance = $instance ?? $this->createDatabaseInstance($dockerService, $hostGenerator);
            
            // Cria o container Docker
            $containerInfo = $dockerService->createContainer($instance);
            
            // Atualiza instância com informações do container
            $instance->update([
                'container_id' => $containerInfo['container_id'],
                'container_name' => $containerInfo['container_name'],
                'volume_name' => $containerInfo['volume_name'],
                'status' => DatabaseInstance::STATUS_RUNNING,
                'provisioned_at' => now(),
            ]);

            // Marca request como completo
            $this->request->markAsCompleted($instance);

            Log::info("Banco de dados provisionado com sucesso", [
                'request_id' => $this->request->id,
                'instance_id' => $instance->id,
                'host' => $instance->host,
            ]);

            // Notifica sistema principal via callback
            $this->notifyCallback($instance);

        } catch (\Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Cria a instância do banco de dados
     */
    private function createDatabaseInstance(
        DockerService $dockerService,
        HostGeneratorService $hostGenerator
    ): DatabaseInstance {
        $config = $this->request->config;

        // Gera credenciais
        $host = $hostGenerator->generateHost();
        $port = $dockerService->generateUniquePort($this->request->engine);
        $username = $this->generateUsername();
        
        // Usa a senha definida pelo usuário ou gera uma nova como fallback
        $password = $this->request->getPassword() ?? $this->generatePassword();
        
        $databaseName = $this->isRelational() ? $this->generateDatabaseName() : null;
        
        // Gera nome do container antecipadamente
        $containerName = 'db_' . Str::lower(Str::random(12));

        return DB::transaction(function () use ($config, $host, $port, $username, $password, $databaseName, $containerName) {
            return DatabaseInstance::create([
                'engine' => $this->request->engine,
                'vcpu' => $config['vcpu'] ?? 1,
                'ram_mb' => $config['ram_mb'] ?? 512,
                'disk_gb' => $config['disk_gb'] ?? null,
                'host' => $host,
                'port' => $port,
                'database_name' => $databaseName,
                'username' => $username,
                'password_encrypted' => encrypt($password),
                'status' => DatabaseInstance::STATUS_PROVISIONING,
                'external_user_id' => $this->request->external_user_id,
                'external_slot_id' => $this->request->external_slot_id,
                'external_request_id' => $this->request->uuid,
                'container_name' => $containerName, // Preencher antes de criar
            ]);
        });
    }

    /**
     * Gera username para o banco (sempre root para facilitar)
     */
    private function generateUsername(): string
    {
        // Para PostgreSQL usa 'postgres', para MySQL usa 'root', para Redis não precisa
        return match($this->request->engine) {
            DatabaseInstance::ENGINE_POSTGRES => 'postgres',
            DatabaseInstance::ENGINE_MYSQL => 'root',
            default => 'root',
        };
    }

    /**
     * Gera senha segura
     */
    private function generatePassword(): string
    {
        return Str::random(32);
    }

    /**
     * Gera nome do banco de dados
     */
    private function generateDatabaseName(): string
    {
        return 'db_' . Str::random(8);
    }

    /**
     * Verifica se é banco relacional
     */
    private function isRelational(): bool
    {
        return in_array($this->request->engine, [
            DatabaseInstance::ENGINE_POSTGRES,
            DatabaseInstance::ENGINE_MYSQL,
        ]);
    }

    /**
     * Notifica sistema principal via callback URL
     */
    private function notifyCallback(DatabaseInstance $instance): void
    {
        if (empty($this->request->callback_url)) {
            return;
        }

        try {
            $response = Http::timeout(30)->post($this->request->callback_url, [
                'request_id' => $this->request->uuid,
                'status' => 'completed',
                'instance' => [
                    'id' => $instance->id,
                    'uuid' => $instance->uuid,
                    'host' => $instance->host,
                    'port' => $instance->port,
                    'username' => $instance->username,
                    'password' => $instance->password,
                    'database' => $instance->database_name,
                    'engine' => $instance->engine,
                ],
            ]);

            Log::info("Callback enviado", [
                'url' => $this->request->callback_url,
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::warning("Falha ao enviar callback", [
                'url' => $this->request->callback_url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trata falha no provisionamento
     */
    private function handleFailure(\Throwable $e): void
    {
        Log::error("Erro no provisionamento de banco de dados", [
            'request_id' => $this->request->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->request->markAsFailed($e->getMessage());

        // Notifica falha via callback
        if (!empty($this->request->callback_url)) {
            try {
                Http::timeout(30)->post($this->request->callback_url, [
                    'request_id' => $this->request->uuid,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $callbackError) {
                Log::warning("Falha ao enviar callback de erro", [
                    'url' => $this->request->callback_url,
                    'error' => $callbackError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Configura retentativas
     */
    public function backoff(): array
    {
        return [10, 30, 60]; // 10s, 30s, 60s
    }
}

