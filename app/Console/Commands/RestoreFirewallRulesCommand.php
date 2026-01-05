<?php

namespace App\Console\Commands;

use App\Services\FirewallService;
use Illuminate\Console\Command;

class RestoreFirewallRulesCommand extends Command
{
    protected $signature = 'firewall:restore {--from-file : Restaura do arquivo de persistência} {--from-db : Restaura do banco de dados}';
    
    protected $description = 'Restaura as regras de firewall após reinício do servidor';

    public function handle(FirewallService $firewallService): int
    {
        $this->info('Restaurando regras de firewall...');

        if ($this->option('from-file')) {
            $this->info('Restaurando do arquivo de persistência...');
            $success = $firewallService->restoreRules();
            
            if ($success) {
                $this->info('✓ Regras restauradas do arquivo com sucesso!');
            } else {
                $this->warn('⚠ Falha ao restaurar do arquivo, tentando do banco de dados...');
                $count = $firewallService->restoreAllFromDatabase();
                $this->info("✓ {$count} regras restauradas do banco de dados.");
            }
        } elseif ($this->option('from-db')) {
            $this->info('Restaurando do banco de dados...');
            $count = $firewallService->restoreAllFromDatabase();
            $this->info("✓ {$count} regras restauradas do banco de dados.");
        } else {
            // Tenta primeiro do arquivo, depois do banco
            $this->info('Tentando restaurar do arquivo de persistência...');
            $success = $firewallService->restoreRules();
            
            if (!$success) {
                $this->info('Restaurando do banco de dados...');
                $count = $firewallService->restoreAllFromDatabase();
                $this->info("✓ {$count} regras restauradas do banco de dados.");
            } else {
                $this->info('✓ Regras restauradas do arquivo com sucesso!');
            }
        }

        // Salva as regras para garantir persistência
        $firewallService->saveRules();

        return Command::SUCCESS;
    }
}

