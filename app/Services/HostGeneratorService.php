<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;

class HostGeneratorService
{
    /**
     * Retorna o host do servidor de banco de dados
     * Usa o IP ou hostname configurado no .env
     */
    public function generateHost(): string
    {
        // Usa o IP/hostname configurado ou detecta automaticamente
        $host = config('database-provisioner.host', env('DB_PROVISIONER_HOST', $this->detectServerIp()));
        
        Log::info("Host do banco de dados", ['host' => $host]);

        return $host;
    }

    /**
     * Detecta o IP público do servidor
     */
    private function detectServerIp(): string
    {
        // Tenta obter IP da interface de rede
        $ip = shell_exec("hostname -I | awk '{print $1}'");
        $ip = trim($ip ?? '');
        
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        // Fallback para localhost
        return '127.0.0.1';
    }

    /**
     * Valida se é um IP ou hostname válido
     */
    public function isValidHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false 
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}

