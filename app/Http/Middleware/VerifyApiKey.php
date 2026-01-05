<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Verifica se a requisição contém uma API Key válida.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key') ?? $request->header('Authorization');
        
        // Remove "Bearer " se presente
        if ($apiKey && str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }

        $validKey = config('services.api_key');

        if (empty($validKey)) {
            // Se não configurado, permite acesso (para não quebrar em desenvolvimento)
            return $next($request);
        }

        if (empty($apiKey) || !hash_equals($validKey, $apiKey)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API Key inválida ou não fornecida',
            ], 401);
        }

        return $next($request);
    }
}

