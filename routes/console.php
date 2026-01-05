<?php

use App\Jobs\BackupAllDatabasesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
*/

// Backup diário de todos os bancos às 03:00 (America/Sao_Paulo)
Schedule::job(new BackupAllDatabasesJob())
    ->dailyAt('03:00')
    ->timezone('America/Sao_Paulo')
    ->name('backup-daily')
    ->withoutOverlapping()
    ->onOneServer();

// Restaura regras de firewall ao iniciar (caso o servidor tenha reiniciado)
Schedule::command('firewall:restore --from-db')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('firewall-check');

// Comando para executar backup manualmente
Artisan::command('backup:run {--database= : ID específico do banco}', function () {
    $backupService = app(\App\Services\BackupService::class);
    
    if ($databaseId = $this->option('database')) {
        $db = \App\Models\DatabaseInstance::find($databaseId);
        if (!$db) {
            $this->error("Banco de dados #{$databaseId} não encontrado");
            return 1;
        }
        
        $this->info("Fazendo backup do banco #{$databaseId}...");
        $result = $backupService->backupDatabase($db);
        
        if ($result['success']) {
            $this->info("✓ Backup concluído: {$result['s3_key']}");
        } else {
            $this->error("✗ Falha: {$result['error']}");
            return 1;
        }
    } else {
        $this->info("Disparando backup de todos os bancos...");
        BackupAllDatabasesJob::dispatch();
        $this->info("✓ Job de backup enfileirado!");
    }
    
    return 0;
})->purpose('Executa backup de bancos de dados');
