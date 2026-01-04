<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;

class FirewallService
{
    /**
     * Ativa o firewall para um banco de dados
     * Bloqueia todo tráfego externo e só permite IPs na whitelist
     */
    public function enableFirewall(DatabaseInstance $db): bool
    {
        try {
            $port = $db->port;
            $containerName = $db->container_name;

            // Primeiro, limpa regras existentes para esta porta
            $this->clearRules($port);

            // Bloqueia todo tráfego para a porta do banco
            $this->executeIptables("-I DOCKER-USER -p tcp --dport {$port} -j DROP");

            // Permite localhost
            $this->executeIptables("-I DOCKER-USER -p tcp --dport {$port} -s 127.0.0.1 -j ACCEPT");

            // Permite rede Docker interna
            $this->executeIptables("-I DOCKER-USER -p tcp --dport {$port} -s 172.16.0.0/12 -j ACCEPT");

            // Adiciona IPs da whitelist
            $rules = $db->firewall_rules ?? [];
            foreach ($rules as $rule) {
                if ($rule['action'] === 'allow' && !empty($rule['ip'])) {
                    $this->addAllowRule($port, $rule['ip']);
                }
            }

            $db->update(['firewall_enabled' => true]);

            Log::info("Firewall ativado para banco de dados", [
                'database_id' => $db->id,
                'port' => $port,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao ativar firewall", [
                'database_id' => $db->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Desativa o firewall - permite todo tráfego
     */
    public function disableFirewall(DatabaseInstance $db): bool
    {
        try {
            $port = $db->port;

            // Remove regras de IPs na whitelist
            $rules = $db->firewall_rules ?? [];
            foreach ($rules as $rule) {
                if (!empty($rule['ip'])) {
                    $this->removeAllowRule($port, $rule['ip']);
                }
            }

            // Remove todas as regras padrão para esta porta
            $this->clearRules($port);

            $db->update(['firewall_enabled' => false]);

            Log::info("Firewall desativado para banco de dados", [
                'database_id' => $db->id,
                'port' => $port,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao desativar firewall", [
                'database_id' => $db->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Adiciona uma regra de liberação de IP
     */
    public function addRule(DatabaseInstance $db, string $ip, string $description = ''): bool
    {
        // Valida IP
        if (!$this->isValidIpOrCidr($ip)) {
            throw new \InvalidArgumentException("IP ou CIDR inválido: {$ip}");
        }

        $rules = $db->firewall_rules ?? [];

        // Verifica se IP já existe
        foreach ($rules as $rule) {
            if ($rule['ip'] === $ip) {
                throw new \InvalidArgumentException("IP já está na lista: {$ip}");
            }
        }

        // Adiciona nova regra
        $rules[] = [
            'ip' => $ip,
            'action' => 'allow',
            'description' => $description,
            'created_at' => now()->toIso8601String(),
        ];

        $db->update(['firewall_rules' => $rules]);

        // Se firewall está ativo, aplica a regra
        if ($db->firewall_enabled) {
            $this->addAllowRule($db->port, $ip);
        }

        Log::info("Regra de firewall adicionada", [
            'database_id' => $db->id,
            'ip' => $ip,
        ]);

        return true;
    }

    /**
     * Remove uma regra de IP
     */
    public function removeRule(DatabaseInstance $db, string $ip): bool
    {
        $rules = $db->firewall_rules ?? [];
        $found = false;

        $rules = array_values(array_filter($rules, function ($rule) use ($ip, &$found) {
            if ($rule['ip'] === $ip) {
                $found = true;
                return false;
            }
            return true;
        }));

        if (!$found) {
            throw new \InvalidArgumentException("IP não encontrado na lista: {$ip}");
        }

        $db->update(['firewall_rules' => $rules]);

        // Se firewall está ativo, remove a regra do iptables
        if ($db->firewall_enabled) {
            $this->removeAllowRule($db->port, $ip);
        }

        Log::info("Regra de firewall removida", [
            'database_id' => $db->id,
            'ip' => $ip,
        ]);

        return true;
    }

    /**
     * Lista todas as regras
     */
    public function listRules(DatabaseInstance $db): array
    {
        return $db->firewall_rules ?? [];
    }

    /**
     * Reaplica todas as regras (útil após reiniciar o servidor)
     */
    public function reapplyRules(DatabaseInstance $db): bool
    {
        if (!$db->firewall_enabled) {
            return true;
        }

        return $this->enableFirewall($db);
    }

    /**
     * Limpa todas as regras para uma porta específica
     */
    private function clearRules(int $port): void
    {
        // Remove regras por especificação ao invés de por número de linha
        // Isso é mais seguro e evita problemas de índice
        
        // Remove regra DROP para a porta
        $this->executeIptables("-D DOCKER-USER -p tcp --dport {$port} -j DROP", false);
        
        // Remove regra de localhost
        $this->executeIptables("-D DOCKER-USER -p tcp --dport {$port} -s 127.0.0.1 -j ACCEPT", false);
        
        // Remove regra de rede Docker
        $this->executeIptables("-D DOCKER-USER -p tcp --dport {$port} -s 172.16.0.0/12 -j ACCEPT", false);
        
        // Tenta remover múltiplas vezes caso haja duplicatas
        for ($i = 0; $i < 10; $i++) {
            $result = shell_exec("sudo iptables -D DOCKER-USER -p tcp --dport {$port} -j ACCEPT 2>&1");
            if (strpos($result ?? '', 'No chain/target/match') !== false || 
                strpos($result ?? '', 'does a matching rule exist') !== false) {
                break;
            }
        }
    }

    /**
     * Adiciona regra de liberação para um IP
     */
    private function addAllowRule(int $port, string $ip): void
    {
        // Insere antes da regra DROP
        $this->executeIptables("-I DOCKER-USER -p tcp --dport {$port} -s {$ip} -j ACCEPT");
    }

    /**
     * Remove regra de liberação para um IP
     */
    private function removeAllowRule(int $port, string $ip): void
    {
        $this->executeIptables("-D DOCKER-USER -p tcp --dport {$port} -s {$ip} -j ACCEPT", false);
    }

    /**
     * Executa comando iptables
     */
    private function executeIptables(string $args, bool $throwOnError = true): bool
    {
        $command = "sudo iptables {$args} 2>&1";
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);
        $outputStr = implode("\n", $output);

        // Ignora erros de regra não encontrada ao deletar
        $isDeleteError = strpos($args, '-D ') !== false && 
            (strpos($outputStr, 'No chain/target/match') !== false ||
             strpos($outputStr, 'does a matching rule exist') !== false ||
             strpos($outputStr, 'Bad rule') !== false);

        if ($exitCode !== 0 && !$isDeleteError && $throwOnError) {
            throw new \RuntimeException("Erro ao executar iptables: " . $outputStr);
        }

        return $exitCode === 0;
    }

    /**
     * Valida IP ou CIDR
     */
    private function isValidIpOrCidr(string $ip): bool
    {
        // IP simples
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // CIDR (ex: 192.168.1.0/24)
        if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $ip)) {
            $parts = explode('/', $ip);
            $ipPart = $parts[0];
            $cidr = (int)$parts[1];

            return filter_var($ipPart, FILTER_VALIDATE_IP) && $cidr >= 0 && $cidr <= 32;
        }

        return false;
    }

    /**
     * Obtém o IP público do cliente (para sugerir ao usuário)
     */
    public function detectClientIp(): ?string
    {
        return request()->ip();
    }
}

