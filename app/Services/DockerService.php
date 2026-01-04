<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DockerService
{
    private const NETWORK_NAME = 'easytidb_net';
    private const DOCKER_HOST_IP = '147.78.120.1'; // IP da máquina Docker host
    private const SSL_CERT_DIR = '/opt/easyti-db-certs';

    /**
     * Verifica se SSL está configurado
     */
    private function isSslEnabled(): bool
    {
        return file_exists(self::SSL_CERT_DIR . '/server.crt') 
            && file_exists(self::SSL_CERT_DIR . '/server.key');
    }

    /**
     * Cria container Docker para banco de dados
     */
    public function createContainer(DatabaseInstance $instance): array
    {
        // Garante que a network existe
        $this->ensureNetworkExists();

        // Remove container existente com mesmo nome (se houver)
        $this->removeExistingContainer($instance->container_name);

        $method = match ($instance->engine) {
            DatabaseInstance::ENGINE_POSTGRES => 'createPostgresContainer',
            DatabaseInstance::ENGINE_MYSQL => 'createMysqlContainer',
            DatabaseInstance::ENGINE_REDIS => 'createRedisContainer',
            default => throw new \InvalidArgumentException("Engine não suportada: {$instance->engine}"),
        };

        return $this->$method($instance);
    }

    /**
     * Remove container existente com o mesmo nome (se houver)
     */
    private function removeExistingContainer(string $containerName): void
    {
        try {
            // Verifica se container existe
            $exists = $this->runCommand(
                "docker ps -aq --filter name=^{$containerName}$",
                false
            );

            if (!empty(trim($exists))) {
                Log::info("Removendo container existente: {$containerName}");
                
                // Para e remove o container
                $this->runCommand("docker stop {$containerName}", false);
                $this->runCommand("docker rm -f {$containerName}", false);
                
                // Remove volumes associados
                $this->runCommand("docker volume rm {$containerName}_data", false);
                $this->runCommand("docker volume rm {$containerName}_ssl", false);
            }
        } catch (\Exception $e) {
            Log::debug("Nenhum container existente para remover: {$containerName}");
        }
    }

    /**
     * Cria container PostgreSQL com SSL
     */
    private function createPostgresContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;
        $sslVolumeName = "{$instance->container_name}_ssl";

        // Cria volumes
        $this->runCommand("docker volume create {$volumeName}");
        
        if ($this->isSslEnabled()) {
            // Cria volume para SSL e copia certificados
            $this->runCommand("docker volume create {$sslVolumeName}");
            
            // Container temporário para copiar certificados (UID 70 = postgres no Alpine)
            $this->runCommand(sprintf(
                'docker run --rm -v %s:/ssl -v %s:/certs alpine sh -c "cp /certs/server.crt /certs/server.key /ssl/ && chown 70:70 /ssl/* && chmod 600 /ssl/server.key && chmod 644 /ssl/server.crt"',
                $sslVolumeName,
                self::SSL_CERT_DIR
            ));

            // Cria container com SSL
            $cmd = sprintf(
                'docker run -d ' .
                '--name %s ' .
                '--network %s ' .
                '-p %d:5432 ' .
                '--cpus=%d ' .
                '--memory=%dm ' .
                '-v %s:/var/lib/postgresql/data ' .
                '-v %s:/var/lib/postgresql/ssl:ro ' .
                '-e POSTGRES_USER=%s ' .
                '-e POSTGRES_PASSWORD=%s ' .
                '-e POSTGRES_DB=%s ' .
                '--restart unless-stopped ' .
                'postgres:16-alpine ' .
                '-c ssl=on ' .
                '-c ssl_cert_file=/var/lib/postgresql/ssl/server.crt ' .
                '-c ssl_key_file=/var/lib/postgresql/ssl/server.key',
                $containerName,
                self::NETWORK_NAME,
                $instance->port,
                $instance->vcpu,
                $instance->ram_mb,
                $volumeName,
                $sslVolumeName,
                $instance->username,
                $instance->password,
                $instance->database_name
            );
        } else {
            // Cria container sem SSL
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
        }

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
            'ssl_enabled' => $this->isSslEnabled(),
        ];
    }

    /**
     * Cria container MySQL com SSL
     */
    private function createMysqlContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;
        $sslVolumeName = "{$instance->container_name}_ssl";

        // Cria volume
        $this->runCommand("docker volume create {$volumeName}");

        if ($this->isSslEnabled()) {
            // Cria volume para SSL e copia certificados
            $this->runCommand("docker volume create {$sslVolumeName}");
            
            $this->runCommand(sprintf(
                'docker run --rm -v %s:/ssl -v %s:/certs alpine sh -c "cp /certs/server.crt /certs/server.key /ssl/ && chmod 644 /ssl/*"',
                $sslVolumeName,
                self::SSL_CERT_DIR
            ));

            // Cria container com SSL
            $cmd = sprintf(
                'docker run -d ' .
                '--name %s ' .
                '--network %s ' .
                '-p %d:3306 ' .
                '--cpus=%d ' .
                '--memory=%dm ' .
                '-v %s:/var/lib/mysql ' .
                '-v %s:/etc/mysql/ssl:ro ' .
                '-e MYSQL_ROOT_PASSWORD=%s ' .
                '-e MYSQL_USER=%s ' .
                '-e MYSQL_PASSWORD=%s ' .
                '-e MYSQL_DATABASE=%s ' .
                '--restart unless-stopped ' .
                'mysql:8.0 ' .
                '--ssl-ca=/etc/mysql/ssl/server.crt ' .
                '--ssl-cert=/etc/mysql/ssl/server.crt ' .
                '--ssl-key=/etc/mysql/ssl/server.key ' .
                '--require-secure-transport=ON',
                $containerName,
                self::NETWORK_NAME,
                $instance->port,
                $instance->vcpu,
                $instance->ram_mb,
                $volumeName,
                $sslVolumeName,
                $instance->password,
                $instance->username,
                $instance->password,
                $instance->database_name
            );
        } else {
            // Cria container sem SSL
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
                $instance->password,
                $instance->username,
                $instance->password,
                $instance->database_name
            );
        }

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
            'ssl_enabled' => $this->isSslEnabled(),
        ];
    }

    /**
     * Cria container Redis com SSL
     */
    private function createRedisContainer(DatabaseInstance $instance): array
    {
        $volumeName = "{$instance->container_name}_data";
        $containerName = $instance->container_name;
        $sslVolumeName = "{$instance->container_name}_ssl";

        // Cria volume
        $this->runCommand("docker volume create {$volumeName}");

        if ($this->isSslEnabled()) {
            // Cria volume para SSL e copia certificados
            $this->runCommand("docker volume create {$sslVolumeName}");
            
            $this->runCommand(sprintf(
                'docker run --rm -v %s:/ssl -v %s:/certs alpine sh -c "cp /certs/server.crt /certs/server.key /ssl/ && chmod 644 /ssl/*"',
                $sslVolumeName,
                self::SSL_CERT_DIR
            ));

            // Cria container Redis com TLS
            $cmd = sprintf(
                'docker run -d ' .
                '--name %s ' .
                '--network %s ' .
                '-p %d:6379 ' .
                '--cpus=%d ' .
                '--memory=%dm ' .
                '-v %s:/data ' .
                '-v %s:/etc/redis/ssl:ro ' .
                '--restart unless-stopped ' .
                'redis:7-alpine redis-server ' .
                '--appendonly yes ' .
                '--requirepass %s ' .
                '--tls-port 6379 ' .
                '--port 0 ' .
                '--tls-cert-file /etc/redis/ssl/server.crt ' .
                '--tls-key-file /etc/redis/ssl/server.key ' .
                '--tls-auth-clients no',
                $containerName,
                self::NETWORK_NAME,
                $instance->port,
                $instance->vcpu,
                $instance->ram_mb,
                $volumeName,
                $sslVolumeName,
                $instance->password
            );
        } else {
            // Cria container Redis sem SSL
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
        }

        $result = $this->runCommand($cmd);

        return [
            'container_id' => trim($result),
            'container_name' => $containerName,
            'volume_name' => $volumeName,
            'ssl_enabled' => $this->isSslEnabled(),
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
            $cmd = "docker inspect -f '{{.State.Running}}' {$instance->container_name}";
            $result = $this->runCommand($cmd, false);
            $trimmed = trim($result);
            
            Log::debug("isContainerRunning check", [
                'container_name' => $instance->container_name,
                'command' => $cmd,
                'raw_result' => $result,
                'trimmed_result' => $trimmed,
                'is_true' => $trimmed === 'true',
            ]);
            
            return $trimmed === 'true';
        } catch (\Exception $e) {
            Log::warning("isContainerRunning exception", [
                'container_name' => $instance->container_name,
                'error' => $e->getMessage(),
            ]);
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
     * Usa portas padrão: PostgreSQL 5432, MySQL 3306, Redis 6379
     * Se já houver um banco na porta padrão, incrementa a partir de portas altas
     */
    public function generateUniquePort(string $engine): int
    {
        // Portas padrão
        $defaultPort = match ($engine) {
            DatabaseInstance::ENGINE_POSTGRES => 5432,
            DatabaseInstance::ENGINE_MYSQL => 3306,
            DatabaseInstance::ENGINE_REDIS => 6379,
            default => 10000,
        };

        // Portas altas para quando já existir banco na porta padrão
        $highPort = match ($engine) {
            DatabaseInstance::ENGINE_POSTGRES => 15432,
            DatabaseInstance::ENGINE_MYSQL => 13306,
            DatabaseInstance::ENGINE_REDIS => 16379,
            default => 10000,
        };

        // Verifica se já existe banco usando a porta padrão
        $existsOnDefaultPort = DatabaseInstance::where('engine', $engine)
            ->where('port', $defaultPort)
            ->whereNotIn('status', [DatabaseInstance::STATUS_DELETED])
            ->exists();

        if (!$existsOnDefaultPort) {
            return $defaultPort;
        }

        // Busca última porta usada para esta engine (portas altas)
        $lastPort = DatabaseInstance::where('engine', $engine)
            ->where('port', '>=', $highPort)
            ->whereNotNull('port')
            ->orderBy('port', 'desc')
            ->value('port');

        if ($lastPort && $lastPort >= $highPort) {
            return $lastPort + 1;
        }

        return $highPort;
    }

    /**
     * Reinicia container
     */
    public function restartContainer(DatabaseInstance $instance): bool
    {
        try {
            $this->runCommand("docker restart {$instance->container_name}");
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao reiniciar container", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obtém métricas do container
     */
    public function getContainerMetrics(DatabaseInstance $instance): array
    {
        $metrics = [
            'running' => false,
            'cpu_percent' => 0,
            'memory_usage' => 0,
            'memory_limit' => $instance->ram_mb * 1024 * 1024,
            'memory_percent' => 0,
            'network_rx' => 0,
            'network_tx' => 0,
            'disk_read' => 0,
            'disk_write' => 0,
            'uptime' => null,
        ];

        try {
            // Verifica se está rodando
            $running = $this->isContainerRunning($instance);
            $metrics['running'] = $running;

            if (!$running) {
                return $metrics;
            }

            // Obtém stats do container (formato JSON)
            $statsJson = $this->runCommand(
                "docker stats {$instance->container_name} --no-stream --format '{{json .}}'",
                false
            );

            if (!empty($statsJson)) {
                $stats = json_decode(trim($statsJson), true);
                
                if ($stats) {
                    // CPU
                    $cpuStr = $stats['CPUPerc'] ?? '0%';
                    $metrics['cpu_percent'] = (float) str_replace('%', '', $cpuStr);

                    // Memory
                    $memStr = $stats['MemPerc'] ?? '0%';
                    $metrics['memory_percent'] = (float) str_replace('%', '', $memStr);
                    
                    // Parse memory usage (ex: "123.4MiB / 2GiB")
                    $memUsage = $stats['MemUsage'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $memUsage, $matches)) {
                        $metrics['memory_usage'] = $this->parseMemorySize($matches[1], $matches[2]);
                        $metrics['memory_limit'] = $this->parseMemorySize($matches[3], $matches[4]);
                    }

                    // Network (ex: "1.5kB / 2.3kB")
                    $netIO = $stats['NetIO'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $netIO, $matches)) {
                        $metrics['network_rx'] = $this->parseMemorySize($matches[1], $matches[2]);
                        $metrics['network_tx'] = $this->parseMemorySize($matches[3], $matches[4]);
                    }

                    // Block I/O (ex: "4.1MB / 0B")
                    $blockIO = $stats['BlockIO'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $blockIO, $matches)) {
                        $metrics['disk_read'] = $this->parseMemorySize($matches[1], $matches[2]);
                        $metrics['disk_write'] = $this->parseMemorySize($matches[3], $matches[4]);
                    }
                }
            }

            // Obtém uptime
            $startedAt = $this->runCommand(
                "docker inspect -f '{{.State.StartedAt}}' {$instance->container_name}",
                false
            );
            if (!empty(trim($startedAt))) {
                $startTime = new \DateTime(trim($startedAt));
                $now = new \DateTime();
                $metrics['uptime'] = $now->getTimestamp() - $startTime->getTimestamp();
            }

        } catch (\Exception $e) {
            Log::warning("Erro ao obter métricas do container", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $metrics;
    }

    /**
     * Converte tamanho de memória para bytes
     */
    private function parseMemorySize(string $value, string $unit): int
    {
        $value = (float) $value;
        $unit = strtoupper($unit);

        return match (true) {
            str_starts_with($unit, 'K') => (int) ($value * 1024),
            str_starts_with($unit, 'M') => (int) ($value * 1024 * 1024),
            str_starts_with($unit, 'G') => (int) ($value * 1024 * 1024 * 1024),
            str_starts_with($unit, 'T') => (int) ($value * 1024 * 1024 * 1024 * 1024),
            default => (int) $value,
        };
    }

    /**
     * Altera a senha do banco de dados
     */
    public function changePassword(DatabaseInstance $instance, string $newPassword): bool
    {
        try {
            $cmd = match ($instance->engine) {
                DatabaseInstance::ENGINE_POSTGRES => sprintf(
                    "docker exec %s psql -U %s -c \"ALTER USER %s WITH PASSWORD '%s';\"",
                    $instance->container_name,
                    $instance->username,
                    $instance->username,
                    addslashes($newPassword)
                ),
                DatabaseInstance::ENGINE_MYSQL => sprintf(
                    "docker exec %s mysql -u root -p'%s' -e \"ALTER USER '%s'@'%%' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;\"",
                    $instance->container_name,
                    $instance->password, // senha atual
                    $instance->username,
                    addslashes($newPassword)
                ),
                DatabaseInstance::ENGINE_REDIS => sprintf(
                    "docker exec %s redis-cli -a '%s' CONFIG SET requirepass '%s'",
                    $instance->container_name,
                    $instance->password, // senha atual
                    addslashes($newPassword)
                ),
                default => throw new \InvalidArgumentException("Engine não suportada"),
            };

            $this->runCommand($cmd);

            Log::info("Senha alterada com sucesso", [
                'instance_id' => $instance->id,
                'engine' => $instance->engine,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao alterar senha", [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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

