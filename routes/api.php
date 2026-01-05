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
        
        // Firewall
        Route::get('/firewall', [DatabaseController::class, 'getFirewall']);
        Route::post('/firewall', [DatabaseController::class, 'toggleFirewall']);
        Route::post('/firewall/rules', [DatabaseController::class, 'addFirewallRule']);
        Route::delete('/firewall/rules', [DatabaseController::class, 'removeFirewallRule']);
        
        // Backups
        Route::get('/backups', [DatabaseController::class, 'listBackups']);
        Route::post('/backups', [DatabaseController::class, 'createBackup']);
        Route::post('/backups/download', [DatabaseController::class, 'downloadBackup']);
        Route::post('/backups/restore', [DatabaseController::class, 'restoreBackup']);
        
        // Backup Jobs (progresso)
        Route::get('/backup-jobs', [DatabaseController::class, 'listBackupJobs']);
        Route::get('/backup-jobs/{jobUuid}', [DatabaseController::class, 'getBackupStatus']);
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

