<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;

class HostGeneratorService
{
    /**
     * Domínio base para os bancos de dados
     */
    private const BASE_DOMAIN = 'easytidatabase.cloud';

    /**
     * Gera um hostname DNS único no formato db<randomNumber>.easytidatabase.cloud
     */
    public function generateHost(): string
    {
        // Gera um número aleatório de 9 dígitos
        $randomNumber = mt_rand(100000000, 999999999);
        
        // Garante que o hostname é único
        $hostname = $this->generateUniqueHostname($randomNumber);
        
        Log::info("Host DNS gerado para banco de dados", ['host' => $hostname]);

        return $hostname;
    }

    /**
     * Gera um hostname único verificando se já existe
     */
    private function generateUniqueHostname(int $baseNumber): string
    {
        $hostname = "db{$baseNumber}." . self::BASE_DOMAIN;
        
        // Verifica se já existe um banco com este hostname
        $exists = DatabaseInstance::where('host', $hostname)->exists();
        
        if ($exists) {
            // Gera novo número e tenta novamente
            $newNumber = mt_rand(100000000, 999999999);
            return $this->generateUniqueHostname($newNumber);
        }
        
        return $hostname;
    }

    /**
     * Retorna o IP real do servidor para configurações internas
     */
    public function getServerIp(): string
    {
        $ip = env('DB_PROVISIONER_HOST', $this->detectServerIp());
        return $ip;
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

    /**
     * Extrai o número do hostname DNS
     */
    public function extractNumberFromHost(string $host): ?int
    {
        if (preg_match('/^db(\d+)\.' . preg_quote(self::BASE_DOMAIN, '/') . '$/', $host, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
}

