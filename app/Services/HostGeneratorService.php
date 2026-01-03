<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;

class HostGeneratorService
{
    private const DOMAIN = 'easytidatabase.cloud';
    private const PREFIX = 'db';

    /**
     * Gera hostname único para nova instância
     * Formato: db<randomNumber>.easytidatabase.cloud
     */
    public function generateHost(): string
    {
        $attempts = 0;
        $maxAttempts = 10;

        do {
            $randomNumber = $this->generateRandomNumber();
            $host = self::PREFIX . $randomNumber . '.' . self::DOMAIN;
            
            // Verifica se já existe
            $exists = DatabaseInstance::where('host', $host)->exists();
            
            $attempts++;
        } while ($exists && $attempts < $maxAttempts);

        if ($exists) {
            throw new \RuntimeException('Não foi possível gerar hostname único após várias tentativas');
        }

        Log::info("Hostname gerado", ['host' => $host]);

        return $host;
    }

    /**
     * Gera número aleatório de 6-10 dígitos
     */
    private function generateRandomNumber(): string
    {
        // Gera número de 6 a 10 dígitos
        $length = random_int(6, 10);
        $min = pow(10, $length - 1);
        $max = pow(10, $length) - 1;
        
        return (string) random_int($min, $max);
    }

    /**
     * Valida se hostname está no formato correto
     */
    public function isValidHost(string $host): bool
    {
        $pattern = '/^' . self::PREFIX . '\d{6,10}\.' . preg_quote(self::DOMAIN, '/') . '$/';
        return preg_match($pattern, $host) === 1;
    }

    /**
     * Extrai o número do hostname
     */
    public function extractNumber(string $host): ?string
    {
        $pattern = '/^' . self::PREFIX . '(\d{6,10})\.' . preg_quote(self::DOMAIN, '/') . '$/';
        if (preg_match($pattern, $host, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

