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

class ProcessRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 3600; // 1 hora para restaurações grandes
    public int $backoff = 120;

    public function __construct(
        public BackupJob $backupJob,
        public string $s3Key
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

        Log::info("Iniciando job de restauração", [
            'backup_job_id' => $backupJob->id,
            'database_id' => $db->id,
            's3_key' => $this->s3Key,
        ]);

        $backupJob->markAsRunning();

        $localFile = null;
        $extractedFile = null;

        try {
            // Step 1: Verificar container (5%)
            $backupJob->updateProgress(5, 'Verificando container...');
            
            if (!$this->isContainerRunning($db->container_name)) {
                // Tenta iniciar o container
                $this->startContainer($db->container_name);
                sleep(5);
                
                if (!$this->isContainerRunning($db->container_name)) {
                    throw new \RuntimeException("Container {$db->container_name} não está em execução");
                }
            }

            // Step 2: Baixando do S3 (10% -> 30%)
            $backupJob->updateProgress(10, 'Baixando backup do S3...');
            $localFile = $this->downloadFromS3($backupService, $this->s3Key, $backupJob);
            
            // Step 3: Verificando integridade (35%)
            $backupJob->updateProgress(35, 'Verificando integridade...');
            if (!file_exists($localFile)) {
                throw new \RuntimeException("Arquivo de backup não encontrado após download");
            }
            $fileSize = filesize($localFile);

            // Step 4: Descomprimindo (40%)
            $backupJob->updateProgress(40, 'Descomprimindo backup...');
            $extractedFile = $this->decompressFile($localFile);

            // Step 5: Parando conexões existentes (45%)
            $backupJob->updateProgress(45, 'Preparando banco de dados...');
            $this->prepareDatabase($db);

            // Step 6: Restaurando dados (50% -> 85%)
            $backupJob->updateProgress(50, 'Restaurando dados...');
            $this->executeRestore($db, $extractedFile, $backupJob);

            // Step 7: Verificando restauração (90%)
            $backupJob->updateProgress(90, 'Verificando restauração...');
            $this->verifyRestore($db);

            // Step 8: Limpando arquivos temporários (95%)
            $backupJob->updateProgress(95, 'Finalizando...');
            @unlink($localFile);
            @unlink($extractedFile);

            // Concluído! (100%)
            $backupJob->markAsCompleted($this->s3Key, $fileSize);

            // Atualiza metadados do banco
            $this->updateDatabaseMetadata($db, $this->s3Key);

            Log::info("Restauração concluída com sucesso", [
                'backup_job_id' => $backupJob->id,
                's3_key' => $this->s3Key,
            ]);

        } catch (\Exception $e) {
            Log::error("Erro no job de restauração", [
                'backup_job_id' => $backupJob->id,
                'error' => $e->getMessage(),
            ]);

            $backupJob->markAsFailed($e->getMessage());

            // Limpa arquivos temporários
            if ($localFile && file_exists($localFile)) {
                @unlink($localFile);
            }
            if ($extractedFile && file_exists($extractedFile)) {
                @unlink($extractedFile);
            }
        }
    }

    private function isContainerRunning(string $containerName): bool
    {
        $output = shell_exec("docker inspect -f '{{.State.Running}}' {$containerName} 2>/dev/null");
        return trim($output ?? '') === 'true';
    }

    private function startContainer(string $containerName): void
    {
        exec("docker start {$containerName} 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Falha ao iniciar container: " . implode("\n", $output));
        }
    }

    private function downloadFromS3(BackupService $backupService, string $s3Key, BackupJob $backupJob): string
    {
        $localPath = '/tmp/' . basename($s3Key);
        
        $backupJob->updateProgress(15, 'Conectando ao S3...');
        
        // Usa o método público do BackupService
        $backupService->downloadFile($s3Key, $localPath);
        
        $backupJob->updateProgress(30, 'Download concluído');
        
        return $localPath;
    }

    private function decompressFile(string $gzFile): string
    {
        $outputFile = str_replace('.gz', '', $gzFile);
        
        exec("gunzip -c {$gzFile} > {$outputFile} 2>&1", $output, $exitCode);
        
        if ($exitCode !== 0) {
            throw new \RuntimeException("Falha ao descomprimir: " . implode("\n", $output));
        }
        
        return $outputFile;
    }

    private function prepareDatabase(DatabaseInstance $db): void
    {
        // Para PostgreSQL/MySQL, fecha conexões existentes
        if ($db->engine === 'postgres') {
            $command = "docker exec {$db->container_name} psql -U postgres -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$db->database_name}' AND pid <> pg_backend_pid();\" 2>&1";
            exec($command);
        }
    }

    private function executeRestore(DatabaseInstance $db, string $sqlFile, BackupJob $backupJob): void
    {
        $command = match($db->engine) {
            'postgres' => $this->buildPgRestoreCommand($db, $sqlFile),
            'mysql' => $this->buildMysqlRestoreCommand($db, $sqlFile),
            'redis' => $this->buildRedisRestoreCommand($db, $sqlFile),
            default => throw new \InvalidArgumentException("Engine não suportada: {$db->engine}"),
        };

        $backupJob->updateProgress(60, 'Importando dados...');

        exec($command, $output, $exitCode);

        $backupJob->updateProgress(85, 'Dados importados');

        if ($exitCode !== 0) {
            $errorOutput = implode("\n", array_slice($output, -5));
            throw new \RuntimeException("Restauração falhou (exit code: {$exitCode}): {$errorOutput}");
        }
    }

    private function buildPgRestoreCommand(DatabaseInstance $db, string $sqlFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        // Copia o arquivo para dentro do container
        exec("docker cp {$sqlFile} {$container}:/tmp/restore.sql 2>&1");

        return "docker exec -e PGPASSWORD='{$password}' {$container} psql -U postgres -d {$database} -f /tmp/restore.sql 2>&1";
    }

    private function buildMysqlRestoreCommand(DatabaseInstance $db, string $sqlFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        // Copia o arquivo para dentro do container
        exec("docker cp {$sqlFile} {$container}:/tmp/restore.sql 2>&1");

        return "docker exec {$container} mysql -u root -p'{$password}' {$database} < /tmp/restore.sql 2>&1";
    }

    private function buildRedisRestoreCommand(DatabaseInstance $db, string $rdbFile): string
    {
        $container = $db->container_name;

        // Para o Redis, copia o RDB e reinicia
        exec("docker cp {$rdbFile} {$container}:/data/dump.rdb 2>&1");
        exec("docker restart {$container} 2>&1");

        return "echo 'Redis restaurado via RDB'";
    }

    private function verifyRestore(DatabaseInstance $db): void
    {
        // Verifica se o banco está acessível após a restauração
        $command = match($db->engine) {
            'postgres' => "docker exec {$db->container_name} psql -U postgres -d {$db->database_name} -c 'SELECT 1;' 2>&1",
            'mysql' => "docker exec {$db->container_name} mysql -u root -p'{$db->password}' -e 'SELECT 1;' {$db->database_name} 2>&1",
            'redis' => "docker exec {$db->container_name} redis-cli PING 2>&1",
            default => "echo 'OK'",
        };

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Verificação pós-restauração falhou");
        }
    }

    private function updateDatabaseMetadata(DatabaseInstance $db, string $s3Key): void
    {
        $metadata = $db->metadata ?? [];
        $metadata['last_restore'] = [
            'timestamp' => now()->toIso8601String(),
            's3_key' => $s3Key,
        ];

        $metadata['restore_history'] = $metadata['restore_history'] ?? [];
        array_unshift($metadata['restore_history'], $metadata['last_restore']);
        $metadata['restore_history'] = array_slice($metadata['restore_history'], 0, 10);

        $db->update(['metadata' => $metadata]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Job de restauração falhou permanentemente", [
            'backup_job_id' => $this->backupJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->backupJob->markAsFailed($exception->getMessage());
    }
}

