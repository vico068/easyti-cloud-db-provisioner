<?php

namespace App\Jobs;

use App\Models\DatabaseInstance;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackupAllDatabasesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600; // 1 hora para todo o processo

    public function __construct()
    {
        //
    }

    public function handle(BackupService $backupService): void
    {
        $startTime = microtime(true);
        $maxConcurrent = config('services.backup.max_concurrent', 6);

        Log::info("=== Iniciando backup diário de bancos de dados ===", [
            'max_concurrent' => $maxConcurrent,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Busca todos os bancos de dados ativos
        $databases = DatabaseInstance::where('status', DatabaseInstance::STATUS_RUNNING)
            ->orderBy('id')
            ->get();

        $total = $databases->count();
        $success = 0;
        $failed = 0;
        $results = [];

        Log::info("Total de bancos para backup: {$total}");

        // Processa em lotes de $maxConcurrent
        foreach ($databases->chunk($maxConcurrent) as $batch) {
            foreach ($batch as $db) {
                try {
                    $result = $backupService->backupDatabase($db);
                    $results[] = $result;

                    if ($result['success']) {
                        $success++;
                    } else {
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    $results[] = [
                        'success' => false,
                        'database_id' => $db->id,
                        'error' => $e->getMessage(),
                    ];
                    
                    Log::error("Erro crítico no backup", [
                        'database_id' => $db->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Limpeza de backups antigos
        Log::info("Iniciando limpeza de backups antigos...");
        $cleaned = $backupService->cleanupOldBackups();

        $duration = round(microtime(true) - $startTime, 2);

        Log::info("=== Backup diário concluído ===", [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'cleaned_old' => $cleaned,
            'duration' => "{$duration}s",
        ]);

        // Se houve falhas, dispara alerta (pode ser integrado com notificações)
        if ($failed > 0) {
            Log::warning("Backup diário teve {$failed} falhas de {$total} bancos");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job de backup diário falhou completamente", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

