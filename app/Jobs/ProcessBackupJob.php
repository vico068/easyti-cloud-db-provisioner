<?php

namespace App\Jobs;

use App\Models\BackupJob;
use App\Models\DatabaseInstance;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800; // 30 minutos
    public int $backoff = 60;

    public function __construct(
        public BackupJob $backupJob
    ) {
        $this->onQueue('backups');
    }

    public function handle(BackupService $backupService): void
    {
        $backupJob = $this->backupJob->fresh();
        
        if (!$backupJob || $backupJob->isCompleted() || $backupJob->isFailed()) {
            return;
        }

        $db = $backupJob->databaseInstance;
        
        if (!$db) {
            $backupJob->markAsFailed('Banco de dados não encontrado');
            return;
        }

        Log::info("Iniciando job de backup", [
            'backup_job_id' => $backupJob->id,
            'database_id' => $db->id,
            'type' => $backupJob->type,
        ]);

        $backupJob->markAsRunning();

        try {
            // Step 1: Verificar container (10%)
            $backupJob->updateProgress(10, 'Verificando container...');
            
            if (!$this->isContainerRunning($db->container_name)) {
                throw new \RuntimeException("Container {$db->container_name} não está em execução");
            }

            // Step 2: Preparando backup (20%)
            $backupJob->updateProgress(20, 'Preparando backup...');
            $timestamp = now()->format('Y-m-d_H-i-s');
            $backupFileName = "backup_{$db->engine}_{$db->id}_{$timestamp}.sql";
            if ($db->engine === 'redis') {
                $backupFileName = "backup_{$db->engine}_{$db->id}_{$timestamp}.rdb";
            }
            $backupFile = "/tmp/{$backupFileName}";

            // Step 3: Executando dump (20% -> 60%)
            $backupJob->updateProgress(30, 'Executando dump do banco...');
            $this->executeDumpWithProgress($db, $backupFile, $backupJob);
            
            // Step 4: Verificando arquivo (65%)
            $backupJob->updateProgress(65, 'Verificando arquivo...');
            if (!file_exists($backupFile) || filesize($backupFile) === 0) {
                throw new \RuntimeException("Arquivo de backup vazio ou não criado");
            }
            $fileSize = filesize($backupFile);

            // Step 5: Comprimindo (70%)
            $backupJob->updateProgress(70, 'Comprimindo backup...');
            $compressedFile = $backupFile . '.gz';
            exec("gzip -c {$backupFile} > {$compressedFile} 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                throw new \RuntimeException("Falha ao comprimir backup");
            }
            $compressedSize = filesize($compressedFile);

            // Step 6: Enviando para S3 (80%)
            $backupJob->updateProgress(80, 'Enviando para a nuvem...');
            $s3Key = $this->uploadToS3($backupService, $db, $compressedFile, $timestamp);

            // Step 7: Limpando arquivos temporários (90%)
            $backupJob->updateProgress(90, 'Finalizando...');
            @unlink($backupFile);
            @unlink($compressedFile);

            // Step 8: Atualizando metadados (95%)
            $backupJob->updateProgress(95, 'Atualizando registros...');
            $this->updateDatabaseMetadata($db, $s3Key, $compressedSize);

            // Concluído! (100%)
            $backupJob->markAsCompleted($s3Key, $compressedSize);

            Log::info("Backup concluído com sucesso", [
                'backup_job_id' => $backupJob->id,
                's3_key' => $s3Key,
                'size' => $compressedSize,
            ]);

        } catch (\Exception $e) {
            Log::error("Erro no job de backup", [
                'backup_job_id' => $backupJob->id,
                'error' => $e->getMessage(),
            ]);

            $backupJob->markAsFailed($e->getMessage());

            // Limpa arquivos temporários
            if (isset($backupFile) && file_exists($backupFile)) {
                @unlink($backupFile);
            }
            if (isset($compressedFile) && file_exists($compressedFile)) {
                @unlink($compressedFile);
            }
        }
    }

    private function isContainerRunning(string $containerName): bool
    {
        $output = shell_exec("docker inspect -f '{{.State.Running}}' {$containerName} 2>/dev/null");
        return trim($output ?? '') === 'true';
    }

    private function executeDumpWithProgress(DatabaseInstance $db, string $outputFile, BackupJob $backupJob): void
    {
        $command = match($db->engine) {
            'postgres' => $this->buildPgDumpCommand($db, $outputFile),
            'mysql' => $this->buildMysqlDumpCommand($db, $outputFile),
            'redis' => $this->buildRedisDumpCommand($db, $outputFile),
            default => throw new \InvalidArgumentException("Engine não suportada: {$db->engine}"),
        };

        // Atualiza progresso durante o dump
        $backupJob->updateProgress(40, 'Dump em andamento...');
        
        exec($command, $output, $exitCode);

        $backupJob->updateProgress(60, 'Dump concluído');

        if ($exitCode !== 0) {
            throw new \RuntimeException("Dump falhou (exit code: {$exitCode}): " . implode("\n", $output));
        }
    }

    private function buildPgDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        return "docker exec -e PGPASSWORD='{$password}' {$container} pg_dump -U postgres -d {$database} > {$outputFile} 2>&1";
    }

    private function buildMysqlDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        return "docker exec {$container} mysqldump -u root -p'{$password}' --single-transaction {$database} > {$outputFile} 2>&1";
    }

    private function buildRedisDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $container = $db->container_name;
        $password = $db->password;

        return "docker exec {$container} redis-cli -a '{$password}' --no-auth-warning BGSAVE && sleep 2 && docker cp {$container}:/data/dump.rdb {$outputFile} 2>&1";
    }

    private function uploadToS3(BackupService $backupService, DatabaseInstance $db, string $filePath, string $timestamp): string
    {
        $date = now()->format('Y/m/d');
        $extension = $db->engine === 'redis' ? 'rdb' : 'sql';
        $s3Key = "backups/{$db->engine}/{$date}/{$db->id}_{$db->container_name}_{$timestamp}.{$extension}.gz";

        // Usa reflexão para acessar o método privado ou cria um público
        $backupService->uploadFile($filePath, $s3Key);

        return $s3Key;
    }

    private function updateDatabaseMetadata(DatabaseInstance $db, string $s3Key, int $size): void
    {
        $metadata = $db->metadata ?? [];
        $metadata['last_backup'] = [
            'timestamp' => now()->toIso8601String(),
            's3_key' => $s3Key,
            'size' => $size,
        ];

        $metadata['backup_history'] = $metadata['backup_history'] ?? [];
        array_unshift($metadata['backup_history'], $metadata['last_backup']);
        $metadata['backup_history'] = array_slice($metadata['backup_history'], 0, 10);

        $db->update(['metadata' => $metadata]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job de backup falhou permanentemente", [
            'backup_job_id' => $this->backupJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->backupJob->markAsFailed($exception->getMessage());
    }
}

