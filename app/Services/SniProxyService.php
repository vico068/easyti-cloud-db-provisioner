<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SniProxyService
{
    private const UPDATE_SCRIPT = '/usr/local/bin/update-db-routes';

    /**
     * Adiciona uma rota SNI para um banco de dados
     */
    public function addRoute(DatabaseInstance $instance): bool
    {
        if (!$this->isAvailable()) {
            Log::warning("SNI Proxy não está disponível, pulando configuração de rota", [
                'instance_id' => $instance->id,
            ]);
            return false;
        }

        $result = $this->runCommand($instance->engine, $instance->host, $instance->port, 'add');

        if ($result) {
            Log::info("Rota SNI adicionada", [
                'engine' => $instance->engine,
                'host' => $instance->host,
                'port' => $instance->port,
            ]);
        }

        return $result;
    }

    /**
     * Remove uma rota SNI de um banco de dados
     */
    public function removeRoute(DatabaseInstance $instance): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $result = $this->runCommand($instance->engine, $instance->host, $instance->port, 'remove');

        if ($result) {
            Log::info("Rota SNI removida", [
                'engine' => $instance->engine,
                'host' => $instance->host,
            ]);
        }

        return $result;
    }

    /**
     * Verifica se o SNI Proxy está disponível
     */
    public function isAvailable(): bool
    {
        return file_exists(self::UPDATE_SCRIPT) && is_executable(self::UPDATE_SCRIPT);
    }

    /**
     * Executa o comando de atualização de rotas
     */
    private function runCommand(string $engine, string $host, int $port, string $action): bool
    {
        try {
            $command = sprintf(
                '%s %s %s %d %s',
                self::UPDATE_SCRIPT,
                escapeshellarg($engine),
                escapeshellarg($host),
                $port,
                $action
            );

            $result = Process::run($command);

            if (!$result->successful()) {
                Log::error("Falha ao executar update-db-routes", [
                    'command' => $command,
                    'error' => $result->errorOutput(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Exceção ao executar update-db-routes", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Retorna informações de conexão com porta padrão (se SNI disponível)
     */
    public function getConnectionInfo(DatabaseInstance $instance): array
    {
        $defaultPorts = [
            DatabaseInstance::ENGINE_POSTGRES => 5432,
            DatabaseInstance::ENGINE_MYSQL => 3306,
            DatabaseInstance::ENGINE_REDIS => 6379,
        ];

        $info = [
            'host' => $instance->host,
            'direct_port' => $instance->port,
            'ssl_required' => false,
        ];

        // Se SNI proxy está disponível, adiciona porta padrão
        if ($this->isAvailable() && isset($defaultPorts[$instance->engine])) {
            $info['sni_port'] = $defaultPorts[$instance->engine];
            $info['ssl_required'] = true;
            $info['connection_string_sni'] = $this->buildConnectionString($instance, true);
        }

        $info['connection_string_direct'] = $this->buildConnectionString($instance, false);

        return $info;
    }

    /**
     * Constrói string de conexão
     */
    private function buildConnectionString(DatabaseInstance $instance, bool $useSni): string
    {
        $defaultPorts = [
            DatabaseInstance::ENGINE_POSTGRES => 5432,
            DatabaseInstance::ENGINE_MYSQL => 3306,
            DatabaseInstance::ENGINE_REDIS => 6379,
        ];

        $port = $useSni ? ($defaultPorts[$instance->engine] ?? $instance->port) : $instance->port;
        $sslMode = $useSni ? 'require' : 'prefer';

        switch ($instance->engine) {
            case DatabaseInstance::ENGINE_POSTGRES:
                return sprintf(
                    "postgresql://%s:%s@%s:%d/%s?sslmode=%s",
                    $instance->username,
                    $instance->password,
                    $instance->host,
                    $port,
                    $instance->database_name,
                    $sslMode
                );

            case DatabaseInstance::ENGINE_MYSQL:
                $ssl = $useSni ? '--ssl-mode=REQUIRED' : '';
                return sprintf(
                    "mysql -h %s -P %d -u %s -p'%s' %s %s",
                    $instance->host,
                    $port,
                    $instance->username,
                    $instance->password,
                    $instance->database_name,
                    $ssl
                );

            case DatabaseInstance::ENGINE_REDIS:
                $tls = $useSni ? '--tls' : '';
                return sprintf(
                    "redis-cli -h %s -p %d -a '%s' %s",
                    $instance->host,
                    $port,
                    $instance->password,
                    $tls
                );

            default:
                return '';
        }
    }
}

