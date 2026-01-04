<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DockerService
{
    private const NETWORK_NAME = 'easytidb_net';

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
                $this->runCommand("docker rm -f {$containerName}", false);
                
                // Remove volumes associados
                $this->runCommand("docker volume rm {$containerName}_data 2>/dev/null", false);
            }
        } catch (\Exception $e) {
            Log::debug("Nenhum container existente para remover: {$containerName}");
        }
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

        // Cria container Redis
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
            $this->runCommand("docker rm -f {$instance->container_name}", false);
            
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
            Log::error("Erro ao parar container", ['error' => $e->getMessage()]);
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
            Log::error("Erro ao iniciar container", ['error' => $e->getMessage()]);
            return false;
        }
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
            Log::error("Erro ao reiniciar container", ['error' => $e->getMessage()]);
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
            $this->runCommand("docker network create " . self::NETWORK_NAME);
            Log::info("Network Docker criada: " . self::NETWORK_NAME);
        }
    }

    /**
     * Gera porta única para novo container
     * SEMPRE usa portas altas para evitar conflito com serviços do sistema
     */
    public function generateUniquePort(string $engine): int
    {
        // Portas base altas (evita conflito com PostgreSQL/MySQL/Redis do sistema)
        $basePort = match ($engine) {
            DatabaseInstance::ENGINE_POSTGRES => 15432,
            DatabaseInstance::ENGINE_MYSQL => 13306,
            DatabaseInstance::ENGINE_REDIS => 16379,
            default => 20000,
        };

        // Busca última porta usada para esta engine
        $lastPort = DatabaseInstance::where('engine', $engine)
            ->whereNotNull('port')
            ->whereNotIn('status', [DatabaseInstance::STATUS_DELETED])
            ->orderBy('port', 'desc')
            ->value('port');

        if ($lastPort && $lastPort >= $basePort) {
            return $lastPort + 1;
        }

        return $basePort;
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
            $running = $this->isContainerRunning($instance);
            $metrics['running'] = $running;

            if (!$running) {
                return $metrics;
            }

            // Obtém stats do container
            $statsJson = $this->runCommand(
                "docker stats {$instance->container_name} --no-stream --format '{{json .}}'",
                false
            );

            if (!empty($statsJson)) {
                $stats = json_decode(trim($statsJson), true);
                
                if ($stats) {
                    $metrics['cpu_percent'] = (float) str_replace('%', '', $stats['CPUPerc'] ?? '0%');
                    $metrics['memory_percent'] = (float) str_replace('%', '', $stats['MemPerc'] ?? '0%');
                    
                    // Parse memory usage
                    $memUsage = $stats['MemUsage'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $memUsage, $m)) {
                        $metrics['memory_usage'] = $this->parseSize($m[1], $m[2]);
                        $metrics['memory_limit'] = $this->parseSize($m[3], $m[4]);
                    }

                    // Parse network
                    $netIO = $stats['NetIO'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $netIO, $m)) {
                        $metrics['network_rx'] = $this->parseSize($m[1], $m[2]);
                        $metrics['network_tx'] = $this->parseSize($m[3], $m[4]);
                    }

                    // Parse block I/O
                    $blockIO = $stats['BlockIO'] ?? '';
                    if (preg_match('/^([\d.]+)(\w+)\s*\/\s*([\d.]+)(\w+)/', $blockIO, $m)) {
                        $metrics['disk_read'] = $this->parseSize($m[1], $m[2]);
                        $metrics['disk_write'] = $this->parseSize($m[3], $m[4]);
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
            Log::warning("Erro ao obter métricas", ['error' => $e->getMessage()]);
        }

        return $metrics;
    }

    /**
     * Converte tamanho para bytes
     */
    private function parseSize(string $value, string $unit): int
    {
        $v = (float) $value;
        $u = strtoupper($unit);

        return match (true) {
            str_starts_with($u, 'K') => (int) ($v * 1024),
            str_starts_with($u, 'M') => (int) ($v * 1024 * 1024),
            str_starts_with($u, 'G') => (int) ($v * 1024 * 1024 * 1024),
            default => (int) $v,
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
                    "docker exec %s mysql -u root -p'%s' -e \"ALTER USER 'root'@'%%' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;\"",
                    $instance->container_name,
                    $instance->password,
                    addslashes($newPassword)
                ),
                DatabaseInstance::ENGINE_REDIS => sprintf(
                    "docker exec %s redis-cli -a '%s' CONFIG SET requirepass '%s'",
                    $instance->container_name,
                    $instance->password,
                    addslashes($newPassword)
                ),
                default => throw new \InvalidArgumentException("Engine não suportada"),
            };

            $this->runCommand($cmd);
            Log::info("Senha alterada", ['instance_id' => $instance->id]);
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao alterar senha", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Executa comando Docker
     */
    private function runCommand(string $command, bool $throwOnError = true): string
    {
        Log::debug("Docker cmd", ['command' => $command]);

        $result = Process::run($command);

        if ($throwOnError && !$result->successful()) {
            throw new \RuntimeException("Comando Docker falhou: {$result->errorOutput()}");
        }

        return $result->output();
    }
}
