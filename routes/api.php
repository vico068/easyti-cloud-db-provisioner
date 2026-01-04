<?php

use App\Http\Controllers\DatabaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| 
| Estas rotas são carregadas automaticamente com o prefixo /api
| pelo Laravel 11. Não adicione prefixo 'api' aqui.
|
*/

// Databases
Route::prefix('/databases')->group(function () {
    Route::get('/', [DatabaseController::class, 'index']);
    Route::post('/', [DatabaseController::class, 'store']);
    
    Route::prefix('/{database}')->where(['database' => '[0-9a-f-]+'])->group(function () {
        Route::get('/', [DatabaseController::class, 'show']);
        Route::post('/start', [DatabaseController::class, 'start']);
        Route::post('/stop', [DatabaseController::class, 'stop']);
        Route::post('/restart', [DatabaseController::class, 'restart']);
        Route::post('/change-password', [DatabaseController::class, 'changePassword']);
        Route::delete('/', [DatabaseController::class, 'destroy']);
        Route::get('/metrics', [DatabaseController::class, 'metrics']);
    });
});

// Provision Requests
Route::get('/requests/{request}', [DatabaseController::class, 'showRequest'])
    ->where('request', '[0-9a-f-]+');

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

