<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('remember_token')->constrained('roles')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->after('role_id')->constrained('users', 'id')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('supervisor_id')->constrained('teams')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['supervisor_id']);
            $table->dropForeign(['team_id']);
            $table->dropColumn(['role_id', 'supervisor_id', 'team_id', 'is_active']);
        });
    }
};
