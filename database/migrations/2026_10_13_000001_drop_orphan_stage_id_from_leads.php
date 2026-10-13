<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'stage_id')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['stage_id']);
            $table->dropColumn('stage_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'stage_id')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
        });
    }
};
