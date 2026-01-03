<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DockerService
{
    private const NETWORK_NAME = 'easytidb_net';
    private const DOCKER_HOST_IP = '147.78.120.1'; // IP da máquina Docker host

    /**
     * Cria container Docker para banco de dados
     */
    public function createContainer(DatabaseInstance $instance): array
    {
        // Garante que a network existe
        $this->ensureNetworkExists();

        $method = match ($instance->engine) {
            DatabaseInstance::ENGINE_POSTGRES => 'createPostgresContainer',
            DatabaseInstance::ENGINE_MYSQL => 'createMysqlContainer',
            DatabaseInstance::ENGINE_REDIS => 'createRedisContainer',
            default => throw new \InvalidArgumentException("Engine não suportada: {$instance->engine}"),
        };

        return $this->$method($instance);
    }

    /**
     * Cria container PostgreSQL
     */
    private function createPostgresContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;

        // Cria volume
        $this->runCommand("docker volume create {$volumeName}");

        // Cria container
        $cmd = sprintf(
            'docker run -d ' .
            '--name %s ' .
            '--network %s ' .
            '-p %d:5432 ' .
            '--cpus=%d ' .
            '--memory=%dm ' .
            '-v %s:/var/lib/postgresql/data ' .
            '-e POSTGRES_USER=%s ' .
            '-e POSTGRES_PASSWORD=%s ' .
            '-e POSTGRES_DB=%s ' .
            '--restart unless-stopped ' .
            'postgres:16-alpine',
            $containerName,
            self::NETWORK_NAME,
            $instance->port,
            $instance->vcpu,
            $instance->ram_mb,
            $volumeName,
            $instance->username,
            $instance->password,
            $instance->database_name
        );

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
        ];
    }

    /**
     * Cria container MySQL
     */
    private function createMysqlContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;

        // Cria volume
        $this->runCommand("docker volume create {$volumeName}");

        // Cria container
        $cmd = sprintf(
            'docker run -d ' .
            '--name %s ' .
            '--network %s ' .
            '-p %d:3306 ' .
            '--cpus=%d ' .
            '--memory=%dm ' .
            '-v %s:/var/lib/mysql ' .
            '-e MYSQL_ROOT_PASSWORD=%s ' .
            '-e MYSQL_USER=%s ' .
            '-e MYSQL_PASSWORD=%s ' .
            '-e MYSQL_DATABASE=%s ' .
            '--restart unless-stopped ' .
            'mysql:8.0',
            $containerName,
            self::NETWORK_NAME,
            $instance->port,
            $instance->vcpu,
            $instance->ram_mb,
            $volumeName,
            $instance->password, // root password
            $instance->username,
            $instance->password,
            $instance->database_name
        );

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
        ];
    }

    /**
     * Cria container Redis
     */
    private function createRedisContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;

        // Cria volume
        $this->runCommand("docker volume create {$volumeName}");

        // Cria container Redis com persistência e senha
        $cmd = sprintf(
            'docker run -d ' .
            '--name %s ' .
            '--network %s ' .
            '-p %d:6379 ' .
            '--cpus=%d ' .
            '--memory=%dm ' .
            '-v %s:/data ' .
            '--restart unless-stopped ' .
            'redis:7-alpine redis-server --appendonly yes --requirepass %s',
            $containerName,
            self::NETWORK_NAME,
            $instance->port,
            $instance->vcpu,
            $instance->ram_mb,
            $volumeName,
            $instance->password
        );

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
        ];
    }

    /**
     * Para e remove container
     */
    public function removeContainer(DatabaseInstance $instance): bool
    {
        try {
            // Para o container
            $this->runCommand("docker stop {$instance->container_name}", false);
            
            // Remove o container
            $this->runCommand("docker rm {$instance->container_name}", false);
            
            // Remove o volume (opcional, dependendo da política)
            if ($instance->volume_name) {
                $this->runCommand("docker volume rm {$instance->volume_name}", false);
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao remover container", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Verifica se container está rodando
     */
    public function isContainerRunning(DatabaseInstance $instance): bool
    {
        try {
            $result = $this->runCommand(
                "docker inspect -f '{{.State.Running}}' {$instance->container_name}",
                false
            );
            return trim($result) === 'true';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Para container
     */
    public function stopContainer(DatabaseInstance $instance): bool
    {
        try {
            $this->runCommand("docker stop {$instance->container_name}");
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao parar container", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Inicia container
     */
    public function startContainer(DatabaseInstance $instance): bool
    {
        try {
            $this->runCommand("docker start {$instance->container_name}");
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao iniciar container", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Garante que a network Docker existe
     */
    private function ensureNetworkExists(): void
    {
        try {
            $this->runCommand("docker network inspect " . self::NETWORK_NAME, false);
        } catch (\Exception $e) {
            // Network não existe, criar
            $this->runCommand("docker network create " . self::NETWORK_NAME);
            Log::info("Network Docker criada: " . self::NETWORK_NAME);
        }
    }

    /**
     * Gera porta única para novo container
     */
    public function generateUniquePort(string $engine): int
    {
        $basePort = match ($engine) {
            DatabaseInstance::ENGINE_POSTGRES => 15432,
            DatabaseInstance::ENGINE_MYSQL => 13306,
            DatabaseInstance::ENGINE_REDIS => 16379,
            default => 10000,
        };

        // Busca última porta usada para esta engine
        $lastPort = DatabaseInstance::where('engine', $engine)
            ->whereNotNull('port')
            ->orderBy('port', 'desc')
            ->value('port');

        if ($lastPort && $lastPort >= $basePort) {
            return $lastPort + 1;
        }

        return $basePort;
    }

    /**
     * Executa comando Docker
     */
    private function runCommand(string $command, bool $throwOnError = true): string
    {
        Log::debug("Executando comando Docker", ['command' => $command]);

        $result = Process::run($command);

        if ($throwOnError && !$result->successful()) {
            throw new \RuntimeException(
                "Comando Docker falhou: {$result->errorOutput()}"
            );
        }

        return $result->output();
    }
}

