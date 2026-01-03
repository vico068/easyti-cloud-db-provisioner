<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Tipo: postgres, mysql, redis
            $table->string('engine', 20);
            
            // Configurações
            $table->integer('vcpu')->default(1);
            $table->integer('ram_mb')->default(512);
            $table->integer('disk_gb')->nullable();
            
            // Conexão
            $table->string('host'); // db<random>.easytidatabase.cloud
            $table->integer('port');
            $table->string('database_name')->nullable(); // null para Redis
            $table->string('username');
            $table->text('password_encrypted');
            
            // Docker
            $table->string('container_id')->nullable();
            $table->string('container_name');
            $table->string('volume_name')->nullable();
            
            // Status: pending, provisioning, running, stopped, failed, deleted
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            
            // Vínculo com sistema principal
            $table->unsignedBigInteger('external_user_id');
            $table->unsignedBigInteger('external_slot_id')->nullable();
            $table->string('external_request_id')->nullable();
            
            // Metadados
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->timestamp('provisioned_at')->nullable();
            $table->softDeletes();
            
            // Índices
            $table->index(['external_user_id', 'status']);
            $table->index(['engine', 'status']);
            $table->index('container_name');
            $table->unique('host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_instances');
    }
};

