<?php

namespace App\Http\Controllers;

use App\Jobs\ProvisionDatabaseJob;
use App\Models\DatabaseInstance;
use App\Models\ProvisionRequest;
use App\Services\DockerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DatabaseController extends Controller
{
    public function __construct(
        private DockerService $dockerService
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
    public function show(DatabaseInstance $database): JsonResponse
    {
        return response()->json([
            'id' => $database->id,
            'uuid' => $database->uuid,
            'engine' => $database->engine,
            'status' => $database->status,
            'host' => $database->host,
            'port' => $database->port,
            'username' => $database->username,
            'password' => $database->password, // Descriptografado pelo accessor
            'database_name' => $database->database_name,
            'container_name' => $database->container_name,
            'container_id' => $database->container_id,
            'volume_name' => $database->volume_name,
            'vcpu' => $database->vcpu,
            'ram_mb' => $database->ram_mb,
            'disk_gb' => $database->disk_gb,
            'external_user_id' => $database->external_user_id,
            'external_slot_id' => $database->external_slot_id,
            'external_request_id' => $database->external_request_id,
            'error_message' => $database->error_message,
            'metadata' => $database->metadata,
            'created_at' => $database->created_at,
            'updated_at' => $database->updated_at,
            'provisioned_at' => $database->provisioned_at,
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
     * Para um banco de dados
     */
    public function stop(DatabaseInstance $database): JsonResponse
    {
        if (!$database->isRunning()) {
            return response()->json([
                'message' => 'Banco de dados não está em execução',
            ], 422);
        }

        $success = $this->dockerService->stopContainer($database);

        if ($success) {
            $database->update(['status' => DatabaseInstance::STATUS_STOPPED]);
            return response()->json(['message' => 'Banco de dados parado com sucesso']);
        }

        return response()->json(['message' => 'Falha ao parar banco de dados'], 500);
    }

    /**
     * Inicia um banco de dados
     */
    public function start(DatabaseInstance $database): JsonResponse
    {
        if ($database->status !== DatabaseInstance::STATUS_STOPPED) {
            return response()->json([
                'message' => 'Banco de dados não está parado',
            ], 422);
        }

        $success = $this->dockerService->startContainer($database);

        if ($success) {
            $database->update(['status' => DatabaseInstance::STATUS_RUNNING]);
            return response()->json(['message' => 'Banco de dados iniciado com sucesso']);
        }

        return response()->json(['message' => 'Falha ao iniciar banco de dados'], 500);
    }

    /**
     * Remove um banco de dados
     */
    public function destroy(DatabaseInstance $database): JsonResponse
    {
        // Remove container Docker
        $this->dockerService->removeContainer($database);

        // Marca como deletado
        $database->update(['status' => DatabaseInstance::STATUS_DELETED]);
        $database->delete(); // Soft delete

        return response()->json(['message' => 'Banco de dados removido com sucesso']);
    }

    /**
     * Obtém métricas de um banco
     */
    public function metrics(DatabaseInstance $database): JsonResponse
    {
        $isRunning = $this->dockerService->isContainerRunning($database);

        return response()->json([
            'id' => $database->id,
            'status' => $isRunning ? 'running' : 'stopped',
            'container_running' => $isRunning,
            // Aqui poderiam ser adicionadas métricas via docker stats
        ]);
    }
}

