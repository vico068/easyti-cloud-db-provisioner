<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_instances', function (Blueprint $table) {
            $table->boolean('firewall_enabled')->default(false)->after('status');
            $table->json('firewall_rules')->nullable()->after('firewall_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('database_instances', function (Blueprint $table) {
            $table->dropColumn(['firewall_enabled', 'firewall_rules']);
        });
    }
};

