<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provision_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Tipo de serviço
            $table->string('service_type', 30); // DB_RELACIONAL, DB_NAO_RELACIONAL
            $table->string('engine', 20); // postgres, mysql, redis
            
            // Configurações solicitadas
            $table->json('config');
            
            // Status: pending, processing, completed, failed, cancelled
            $table->string('status', 20)->default('pending');
            
            // Resultado
            $table->foreignId('database_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error_message')->nullable();
            
            // Origem da requisição
            $table->unsignedBigInteger('external_user_id');
            $table->unsignedBigInteger('external_slot_id')->nullable();
            $table->string('callback_url')->nullable(); // URL para notificar sistema principal
            
            // Metadados
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Índices
            $table->index(['status', 'created_at']);
            $table->index('external_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provision_requests');
    }
};

