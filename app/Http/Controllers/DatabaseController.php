<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionDatabaseJob;
use App\Models\DatabaseInstance;
use App\Models\ProvisionRequest;
use App\Services\DockerService;
use App\Services\FirewallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DatabaseController extends Controller
{
    public function __construct(
        private DockerService $dockerService,
        private FirewallService $firewallService
    ) {
    }

    /**
     * Lista bancos de dados
     */
    public function index(Request $request): JsonResponse
    {
        $query = DatabaseInstance::query();

        // Filtros
        if ($userId = $request->get('user_id')) {
            $query->where('external_user_id', $userId);
        }

        if ($engine = $request->get('engine')) {
            $query->where('engine', $engine);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $instances = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($instances);
    }

    /**
     * Cria solicitação de banco de dados
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'engine' => [
                'required',
                Rule::in([
                    DatabaseInstance::ENGINE_POSTGRES,
                    DatabaseInstance::ENGINE_MYSQL,
                    DatabaseInstance::ENGINE_REDIS,
                ]),
            ],
            'user_id' => 'required|integer',
            'slot_id' => 'nullable|integer',
            'password' => 'required|string|min:8|max:128', // Senha definida pelo usuário
            'config' => 'sometimes|array',
            'config.vcpu' => 'nullable|integer|min:1|max:16',
            'config.ram_mb' => 'nullable|integer|min:128|max:32768',
            'config.disk_gb' => 'nullable|integer|min:1|max:1000',
            'callback_url' => 'nullable|url',
            'metadata' => 'nullable|array',
        ]);

        // Determina tipo de serviço baseado na engine
        $serviceType = in_array($validated['engine'], ['postgres', 'mysql'])
            ? ProvisionRequest::TYPE_DB_RELACIONAL
            : ProvisionRequest::TYPE_DB_NAO_RELACIONAL;

        // Cria request de provisionamento
        $provisionRequest = ProvisionRequest::create([
            'service_type' => $serviceType,
            'engine' => $validated['engine'],
            'config' => $validated['config'] ?? [
                'vcpu' => 1,
                'ram_mb' => 512,
            ],
            'password_encrypted' => encrypt($validated['password']), // Salva senha criptografada
            'external_user_id' => $validated['user_id'],
            'external_slot_id' => $validated['slot_id'] ?? null,
            'callback_url' => $validated['callback_url'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // Enfileira job de provisionamento
        ProvisionDatabaseJob::dispatch($provisionRequest);

        return response()->json([
            'message' => 'Solicitação de banco de dados recebida',
            'request_id' => $provisionRequest->uuid,
            'status' => 'pending',
        ], 202);
    }

    /**
     * Exibe detalhes de um banco de dados
     */
    public function show(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);

        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        return response()->json([
            'id' => $db->id,
            'uuid' => $db->uuid,
            'engine' => $db->engine,
            'status' => $db->status,
            'host' => $db->host,
            'port' => $db->port,
            'username' => $db->username,
            'password' => $db->password,
            'database_name' => $db->database_name,
            'container_name' => $db->container_name,
            'container_id' => $db->container_id,
            'volume_name' => $db->volume_name,
            'vcpu' => $db->vcpu,
            'ram_mb' => $db->ram_mb,
            'disk_gb' => $db->disk_gb,
            'external_user_id' => $db->external_user_id,
            'external_slot_id' => $db->external_slot_id,
            'external_request_id' => $db->external_request_id,
            'error_message' => $db->error_message,
            'metadata' => $db->metadata,
            'created_at' => $db->created_at,
            'updated_at' => $db->updated_at,
            'provisioned_at' => $db->provisioned_at,
        ]);
    }

    /**
     * Exibe status de uma solicitação
     */
    public function showRequest(ProvisionRequest $request): JsonResponse
    {
        $response = [
            'request_id' => $request->uuid,
            'status' => $request->status,
            'engine' => $request->engine,
            'created_at' => $request->created_at,
            'started_at' => $request->started_at,
            'completed_at' => $request->completed_at,
        ];

        if ($request->status === ProvisionRequest::STATUS_COMPLETED && $request->databaseInstance) {
            $response['instance'] = [
                'id' => $request->databaseInstance->id,
                'uuid' => $request->databaseInstance->uuid,
                'host' => $request->databaseInstance->host,
                'port' => $request->databaseInstance->port,
                'credentials' => $request->databaseInstance->getCredentials(),
            ];
        }

        if ($request->status === ProvisionRequest::STATUS_FAILED) {
            $response['error'] = $request->error_message;
        }

        return response()->json($response);
    }

    /**
     * Busca database por UUID ou ID
     */
    private function findDatabase(string $identifier): ?DatabaseInstance
    {
        // Se parece ser um UUID (contém hífens), busca apenas por UUID
        if (str_contains($identifier, '-')) {
            return DatabaseInstance::where('uuid', $identifier)->first();
        }
        
        // Se é numérico, busca por ID
        if (is_numeric($identifier)) {
            return DatabaseInstance::where('id', $identifier)->first();
        }

        // Fallback: tenta buscar por UUID
        return DatabaseInstance::where('uuid', $identifier)->first();
    }

    /**
     * Para um banco de dados
     */
    public function stop(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        if (!$db->isRunning()) {
            return response()->json([
                'message' => 'Banco de dados não está em execução',
            ], 422);
        }

        $success = $this->dockerService->stopContainer($db);

        if ($success) {
            $db->update(['status' => DatabaseInstance::STATUS_STOPPED]);
            return response()->json(['message' => 'Banco de dados parado com sucesso']);
        }

        return response()->json(['message' => 'Falha ao parar banco de dados'], 500);
    }

    /**
     * Inicia um banco de dados
     */
    public function start(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        if ($db->status !== DatabaseInstance::STATUS_STOPPED) {
            return response()->json([
                'message' => 'Banco de dados não está parado',
            ], 422);
        }

        $success = $this->dockerService->startContainer($db);

        if ($success) {
            $db->update(['status' => DatabaseInstance::STATUS_RUNNING]);
            return response()->json(['message' => 'Banco de dados iniciado com sucesso']);
        }

        return response()->json(['message' => 'Falha ao iniciar banco de dados'], 500);
    }

    /**
     * Remove um banco de dados
     */
    public function destroy(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        // Guarda o slot_id antes de deletar
        $externalSlotId = $db->external_slot_id;
        $externalUserId = $db->external_user_id;

        // Remove container Docker
        $this->dockerService->removeContainer($db);

        // Marca como deletado
        $db->update(['status' => DatabaseInstance::STATUS_DELETED]);
        $db->delete(); // Soft delete

        return response()->json([
            'message' => 'Banco de dados removido com sucesso',
            'external_slot_id' => $externalSlotId,
            'external_user_id' => $externalUserId,
        ]);
    }

    /**
     * Obtém métricas de um banco
     */
    public function metrics(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $metrics = $this->dockerService->getContainerMetrics($db);

        return response()->json([
            'id' => $db->id,
            'uuid' => $db->uuid,
            'status' => $metrics['running'] ? 'running' : 'stopped',
            'container_running' => $metrics['running'],
            'cpu' => [
                'usage_percent' => $metrics['cpu_percent'] ?? 0,
            ],
            'memory' => [
                'used_bytes' => $metrics['memory_usage'] ?? 0,
                'limit_bytes' => $metrics['memory_limit'] ?? ($db->ram_mb * 1024 * 1024),
                'usage_percent' => $metrics['memory_percent'] ?? 0,
            ],
            'network' => [
                'rx_bytes' => $metrics['network_rx'] ?? 0,
                'tx_bytes' => $metrics['network_tx'] ?? 0,
            ],
            'disk' => [
                'read_bytes' => $metrics['disk_read'] ?? 0,
                'write_bytes' => $metrics['disk_write'] ?? 0,
            ],
            'uptime' => $metrics['uptime'] ?? null,
        ]);
    }

    /**
     * Altera a senha do banco de dados
     */
    public function changePassword(Request $request, string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8|max:128',
        ]);

        if (!$db->isRunning()) {
            return response()->json([
                'message' => 'Banco de dados precisa estar em execução para alterar a senha',
            ], 422);
        }

        $success = $this->dockerService->changePassword($db, $validated['password']);

        if ($success) {
            // Atualiza a senha criptografada no banco
            $db->password = $validated['password'];
            $db->save();

            return response()->json(['message' => 'Senha alterada com sucesso']);
        }

        return response()->json(['message' => 'Falha ao alterar senha'], 500);
    }

    /**
     * Reinicia um banco de dados
     */
    public function restart(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $success = $this->dockerService->restartContainer($db);

        if ($success) {
            return response()->json(['message' => 'Banco de dados reiniciado com sucesso']);
        }

        return response()->json(['message' => 'Falha ao reiniciar banco de dados'], 500);
    }

    // ==================== FIREWALL ====================

    /**
     * Obtém status do firewall
     */
    public function getFirewall(string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        return response()->json([
            'enabled' => $db->firewall_enabled,
            'rules' => $db->firewall_rules ?? [],
            'client_ip' => $this->firewallService->detectClientIp(),
        ]);
    }

    /**
     * Ativa ou desativa o firewall
     */
    public function toggleFirewall(Request $request, string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ($validated['enabled']) {
            $success = $this->firewallService->enableFirewall($db);
            $message = 'Firewall ativado com sucesso';
        } else {
            $success = $this->firewallService->disableFirewall($db);
            $message = 'Firewall desativado com sucesso';
        }

        if (!$success) {
            return response()->json(['message' => 'Falha ao alterar firewall'], 500);
        }

        return response()->json([
            'message' => $message,
            'enabled' => $db->fresh()->firewall_enabled,
        ]);
    }

    /**
     * Adiciona regra de firewall
     */
    public function addFirewallRule(Request $request, string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $validated = $request->validate([
            'ip' => 'required|string|max:50',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $this->firewallService->addRule($db, $validated['ip'], $validated['description'] ?? '');

            return response()->json([
                'message' => 'Regra adicionada com sucesso',
                'rules' => $db->fresh()->firewall_rules,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao adicionar regra'], 500);
        }
    }

    /**
     * Remove regra de firewall
     */
    public function removeFirewallRule(Request $request, string $database): JsonResponse
    {
        $db = $this->findDatabase($database);
        if (!$db) {
            return response()->json(['message' => 'Banco de dados não encontrado'], 404);
        }

        $validated = $request->validate([
            'ip' => 'required|string',
        ]);

        try {
            $this->firewallService->removeRule($db, $validated['ip']);

            return response()->json([
                'message' => 'Regra removida com sucesso',
                'rules' => $db->fresh()->firewall_rules,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao remover regra'], 500);
        }
    }
}

