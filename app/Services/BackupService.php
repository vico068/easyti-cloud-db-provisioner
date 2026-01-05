<?php

namespace App\Services;

use App\Models\DatabaseInstance;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BackupService
{
    private ?S3Client $s3Client = null;
    private string $bucket;
    private int $retentionDays;

    public function __construct()
    {
        $this->bucket = config('services.backup.s3.bucket');
        $this->retentionDays = config('services.backup.retention_days', 30);
    }

    /**
     * Inicializa cliente S3
     */
    private function getS3Client(): S3Client
    {
        if ($this->s3Client === null) {
            $this->s3Client = new S3Client([
                'version' => 'latest',
                'region' => config('services.backup.s3.region', 'us-east-1'),
                'credentials' => [
                    'key' => config('services.backup.s3.key'),
                    'secret' => config('services.backup.s3.secret'),
                ],
            ]);
        }

        return $this->s3Client;
    }

    /**
     * Faz backup de um banco de dados específico
     */
    public function backupDatabase(DatabaseInstance $db): array
    {
        $startTime = microtime(true);
        $backupFile = null;
        
        try {
            Log::info("Iniciando backup do banco de dados", [
                'database_id' => $db->id,
                'engine' => $db->engine,
                'container' => $db->container_name,
            ]);

            // Verifica se o container está rodando
            $isRunning = $this->isContainerRunning($db->container_name);
            if (!$isRunning) {
                throw new \RuntimeException("Container {$db->container_name} não está em execução");
            }

            // Gera nome do arquivo de backup
            $timestamp = now()->format('Y-m-d_H-i-s');
            $backupFileName = "backup_{$db->engine}_{$db->id}_{$timestamp}.sql";
            if ($db->engine === 'redis') {
                $backupFileName = "backup_{$db->engine}_{$db->id}_{$timestamp}.rdb";
            }
            $backupFile = "/tmp/{$backupFileName}";

            // Executa dump do banco
            $this->executeDump($db, $backupFile);

            // Verifica se o arquivo foi criado
            if (!file_exists($backupFile) || filesize($backupFile) === 0) {
                throw new \RuntimeException("Arquivo de backup vazio ou não criado");
            }

            $fileSize = filesize($backupFile);

            // Comprime o backup
            $compressedFile = $backupFile . '.gz';
            exec("gzip -c {$backupFile} > {$compressedFile} 2>&1", $output, $exitCode);
            
            if ($exitCode !== 0) {
                throw new \RuntimeException("Falha ao comprimir backup");
            }

            $compressedSize = filesize($compressedFile);

            // Upload para S3
            $s3Key = $this->generateS3Key($db, $timestamp, true);
            $this->uploadToS3($compressedFile, $s3Key);

            // Limpa arquivos temporários
            @unlink($backupFile);
            @unlink($compressedFile);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info("Backup concluído com sucesso", [
                'database_id' => $db->id,
                's3_key' => $s3Key,
                'original_size' => $this->formatBytes($fileSize),
                'compressed_size' => $this->formatBytes($compressedSize),
                'duration' => "{$duration}s",
            ]);

            // Atualiza metadados do banco
            $this->updateBackupMetadata($db, $s3Key, $compressedSize);

            return [
                'success' => true,
                'database_id' => $db->id,
                's3_key' => $s3Key,
                'size' => $compressedSize,
                'duration' => $duration,
            ];

        } catch (\Exception $e) {
            Log::error("Erro no backup do banco de dados", [
                'database_id' => $db->id,
                'error' => $e->getMessage(),
            ]);

            // Limpa arquivos temporários em caso de erro
            if ($backupFile && file_exists($backupFile)) {
                @unlink($backupFile);
            }
            if ($backupFile && file_exists($backupFile . '.gz')) {
                @unlink($backupFile . '.gz');
            }

            return [
                'success' => false,
                'database_id' => $db->id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Executa dump do banco de acordo com a engine
     */
    private function executeDump(DatabaseInstance $db, string $outputFile): void
    {
        $command = match($db->engine) {
            'postgres' => $this->buildPgDumpCommand($db, $outputFile),
            'mysql' => $this->buildMysqlDumpCommand($db, $outputFile),
            'redis' => $this->buildRedisDumpCommand($db, $outputFile),
            default => throw new \InvalidArgumentException("Engine não suportada: {$db->engine}"),
        };

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Dump falhou (exit code: {$exitCode}): " . implode("\n", $output));
        }
    }

    /**
     * Comando para dump PostgreSQL
     */
    private function buildPgDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        return "docker exec -e PGPASSWORD='{$password}' {$container} pg_dump -U postgres -d {$database} > {$outputFile} 2>&1";
    }

    /**
     * Comando para dump MySQL
     */
    private function buildMysqlDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        return "docker exec {$container} mysqldump -u root -p'{$password}' --single-transaction {$database} > {$outputFile} 2>&1";
    }

    /**
     * Comando para dump Redis
     */
    private function buildRedisDumpCommand(DatabaseInstance $db, string $outputFile): string
    {
        $container = $db->container_name;
        $password = $db->password;

        // Redis: copia o arquivo RDB
        return "docker exec {$container} redis-cli -a '{$password}' --no-auth-warning BGSAVE && sleep 2 && docker cp {$container}:/data/dump.rdb {$outputFile} 2>&1";
    }

    /**
     * Gera chave S3 organizada por data
     */
    private function generateS3Key(DatabaseInstance $db, string $timestamp, bool $compressed = false): string
    {
        $date = now()->format('Y/m/d');
        $extension = $db->engine === 'redis' ? 'rdb' : 'sql';
        $suffix = $compressed ? '.gz' : '';
        
        return "backups/{$db->engine}/{$date}/{$db->id}_{$db->container_name}_{$timestamp}.{$extension}{$suffix}";
    }

    /**
     * Upload para S3
     */
    private function uploadToS3(string $filePath, string $s3Key): void
    {
        try {
            $this->getS3Client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'SourceFile' => $filePath,
                'ContentType' => 'application/gzip',
                'StorageClass' => 'STANDARD_IA', // Mais barato para backups
                'Metadata' => [
                    'backup-date' => now()->toIso8601String(),
                    'retention-days' => (string) $this->retentionDays,
                ],
            ]);
        } catch (AwsException $e) {
            throw new \RuntimeException("Falha no upload S3: " . $e->getMessage());
        }
    }

    /**
     * Atualiza metadados de backup no banco
     */
    private function updateBackupMetadata(DatabaseInstance $db, string $s3Key, int $size): void
    {
        $metadata = $db->metadata ?? [];
        $metadata['last_backup'] = [
            'timestamp' => now()->toIso8601String(),
            's3_key' => $s3Key,
            'size' => $size,
        ];

        // Mantém histórico dos últimos 10 backups
        $metadata['backup_history'] = $metadata['backup_history'] ?? [];
        array_unshift($metadata['backup_history'], $metadata['last_backup']);
        $metadata['backup_history'] = array_slice($metadata['backup_history'], 0, 10);

        $db->update(['metadata' => $metadata]);
    }

    /**
     * Verifica se container está rodando
     */
    private function isContainerRunning(string $containerName): bool
    {
        $output = shell_exec("docker inspect -f '{{.State.Running}}' {$containerName} 2>/dev/null");
        return trim($output ?? '') === 'true';
    }

    /**
     * Lista backups de um banco no S3
     */
    public function listBackups(DatabaseInstance $db, int $limit = 10): array
    {
        try {
            $prefix = "backups/{$db->engine}/";
            
            $result = $this->getS3Client()->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1000,
            ]);

            $backups = [];
            $dbPrefix = "{$db->id}_{$db->container_name}";

            foreach ($result['Contents'] ?? [] as $object) {
                if (str_contains($object['Key'], $dbPrefix)) {
                    $backups[] = [
                        'key' => $object['Key'],
                        'size' => $object['Size'],
                        'last_modified' => $object['LastModified']->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // Ordena por data (mais recente primeiro)
            usort($backups, fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));

            return array_slice($backups, 0, $limit);
        } catch (AwsException $e) {
            Log::error("Erro ao listar backups S3", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Remove backups antigos (além do período de retenção)
     */
    public function cleanupOldBackups(): int
    {
        $count = 0;
        $cutoffDate = now()->subDays($this->retentionDays);

        try {
            $result = $this->getS3Client()->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => 'backups/',
            ]);

            $toDelete = [];

            foreach ($result['Contents'] ?? [] as $object) {
                if ($object['LastModified'] < $cutoffDate) {
                    $toDelete[] = ['Key' => $object['Key']];
                    $count++;
                }
            }

            if (!empty($toDelete)) {
                // Deleta em lotes de 1000
                foreach (array_chunk($toDelete, 1000) as $batch) {
                    $this->getS3Client()->deleteObjects([
                        'Bucket' => $this->bucket,
                        'Delete' => ['Objects' => $batch],
                    ]);
                }
            }

            Log::info("Limpeza de backups antigos concluída", [
                'deleted' => $count,
                'retention_days' => $this->retentionDays,
            ]);

        } catch (AwsException $e) {
            Log::error("Erro na limpeza de backups", ['error' => $e->getMessage()]);
        }

        return $count;
    }

    /**
     * Formata bytes para leitura humana
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Gera URL de download pré-assinada do S3
     */
    public function getDownloadUrl(string $s3Key, int $expiresIn = 3600): string
    {
        $cmd = $this->getS3Client()->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $s3Key,
        ]);

        $request = $this->getS3Client()->createPresignedRequest($cmd, "+{$expiresIn} seconds");
        
        return (string) $request->getUri();
    }

    /**
     * Restaura banco de dados a partir de backup S3
     */
    public function restoreDatabase(DatabaseInstance $db, string $s3Key): array
    {
        $startTime = microtime(true);
        $localFile = null;
        $decompressedFile = null;

        try {
            Log::info("Iniciando restauração do banco de dados", [
                'database_id' => $db->id,
                's3_key' => $s3Key,
            ]);

            // Verifica se o container está rodando
            if (!$this->isContainerRunning($db->container_name)) {
                throw new \RuntimeException("Container {$db->container_name} não está em execução");
            }

            // Baixa backup do S3
            $timestamp = now()->format('Y-m-d_H-i-s');
            $localFile = "/tmp/restore_{$db->id}_{$timestamp}.sql.gz";
            
            $result = $this->getS3Client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $s3Key,
                'SaveAs' => $localFile,
            ]);

            // Descomprime
            $decompressedFile = str_replace('.gz', '', $localFile);
            exec("gunzip -c {$localFile} > {$decompressedFile} 2>&1", $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException("Falha ao descomprimir backup");
            }

            // Restaura
            $this->executeRestore($db, $decompressedFile);

            // Limpa arquivos temporários
            @unlink($localFile);
            @unlink($decompressedFile);

            $duration = round(microtime(true) - $startTime, 2);

            Log::info("Restauração concluída com sucesso", [
                'database_id' => $db->id,
                's3_key' => $s3Key,
                'duration' => "{$duration}s",
            ]);

            // Atualiza metadados
            $metadata = $db->metadata ?? [];
            $metadata['last_restore'] = [
                'timestamp' => now()->toIso8601String(),
                's3_key' => $s3Key,
            ];
            $db->update(['metadata' => $metadata]);

            return [
                'success' => true,
                'duration' => $duration,
            ];

        } catch (\Exception $e) {
            Log::error("Erro na restauração do banco de dados", [
                'database_id' => $db->id,
                's3_key' => $s3Key,
                'error' => $e->getMessage(),
            ]);

            // Limpa arquivos temporários
            if ($localFile && file_exists($localFile)) {
                @unlink($localFile);
            }
            if ($decompressedFile && file_exists($decompressedFile)) {
                @unlink($decompressedFile);
            }

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Executa restauração de acordo com a engine
     */
    private function executeRestore(DatabaseInstance $db, string $inputFile): void
    {
        $command = match($db->engine) {
            'postgres' => $this->buildPgRestoreCommand($db, $inputFile),
            'mysql' => $this->buildMysqlRestoreCommand($db, $inputFile),
            'redis' => $this->buildRedisRestoreCommand($db, $inputFile),
            default => throw new \InvalidArgumentException("Engine não suportada para restore: {$db->engine}"),
        };

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException("Restauração falhou (exit code: {$exitCode}): " . implode("\n", $output));
        }
    }

    /**
     * Comando para restaurar PostgreSQL
     */
    private function buildPgRestoreCommand(DatabaseInstance $db, string $inputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        // Primeiro limpa o banco, depois restaura
        return "docker exec -e PGPASSWORD='{$password}' {$container} psql -U postgres -d {$database} -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;' && " .
               "docker exec -i -e PGPASSWORD='{$password}' {$container} psql -U postgres -d {$database} < {$inputFile} 2>&1";
    }

    /**
     * Comando para restaurar MySQL
     */
    private function buildMysqlRestoreCommand(DatabaseInstance $db, string $inputFile): string
    {
        $password = $db->password;
        $database = $db->database_name;
        $container = $db->container_name;

        return "docker exec -i {$container} mysql -u root -p'{$password}' {$database} < {$inputFile} 2>&1";
    }

    /**
     * Comando para restaurar Redis
     */
    private function buildRedisRestoreCommand(DatabaseInstance $db, string $inputFile): string
    {
        $container = $db->container_name;

        // Para Redis, copia o arquivo RDB
        return "docker cp {$inputFile} {$container}:/data/dump.rdb && " .
               "docker exec {$container} redis-cli BGSAVE 2>&1";
    }
}

