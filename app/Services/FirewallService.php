<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Illuminate\Support\Facades\Log;

class FirewallService
{
    private const RULES_FILE = '/etc/iptables/rules.v4';
    private const BACKUP_FILE = '/etc/iptables/rules.v4.backup';
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

            // Persiste as regras
            $this->saveRules();

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

            // Persiste as regras
            $this->saveRules();

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
            $this->saveRules();
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
            $this->saveRules();
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

    // ==================== PERSISTÊNCIA ====================

    /**
     * Salva as regras atuais do iptables para persistência
     */
    public function saveRules(): bool
    {
        try {
            // Garante que o diretório existe
            if (!is_dir('/etc/iptables')) {
                exec('sudo mkdir -p /etc/iptables 2>&1', $output, $exitCode);
            }

            // Salva as regras
            $command = 'sudo iptables-save > ' . self::RULES_FILE . ' 2>&1';
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                // Tenta método alternativo
                $rules = shell_exec('sudo iptables-save 2>&1');
                if ($rules) {
                    exec("echo " . escapeshellarg($rules) . " | sudo tee " . self::RULES_FILE . " > /dev/null 2>&1", $output, $exitCode);
                }
            }

            Log::info("Regras de iptables salvas com sucesso");
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao salvar regras de iptables", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Restaura as regras do arquivo de persistência
     */
    public function restoreRules(): bool
    {
        try {
            if (!file_exists(self::RULES_FILE)) {
                Log::info("Arquivo de regras não encontrado, pulando restauração");
                return true;
            }

            $command = 'sudo iptables-restore < ' . self::RULES_FILE . ' 2>&1';
            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                Log::warning("Falha ao restaurar regras do arquivo", ['output' => implode("\n", $output)]);
                return false;
            }

            Log::info("Regras de iptables restauradas com sucesso");
            return true;
        } catch (\Exception $e) {
            Log::error("Erro ao restaurar regras de iptables", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Restaura todas as regras de firewall do banco de dados
     * Útil após reiniciar o servidor ou em caso de falha
     */
    public function restoreAllFromDatabase(): int
    {
        $count = 0;
        $databases = DatabaseInstance::where('firewall_enabled', true)->get();

        foreach ($databases as $db) {
            try {
                if ($this->enableFirewall($db)) {
                    $count++;
                }
            } catch (\Exception $e) {
                Log::error("Erro ao restaurar firewall para banco {$db->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Regras de firewall restauradas do banco de dados", ['count' => $count]);
        return $count;
    }
}

