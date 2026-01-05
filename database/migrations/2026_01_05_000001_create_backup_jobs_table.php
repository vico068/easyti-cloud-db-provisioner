<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('database_instance_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['manual', 'scheduled', 'restore'])->default('manual');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('progress')->default(0); // 0-100
            $table->string('current_step')->nullable(); // Etapa atual
            $table->bigInteger('size_bytes')->nullable(); // Tamanho do backup
            $table->string('s3_key')->nullable(); // Chave no S3
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable(); // Dados extras
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['database_instance_id', 'status']);
            $table->index('uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};

