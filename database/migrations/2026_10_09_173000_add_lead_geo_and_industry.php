<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('geo_location', 120)->nullable()->after('customer_segment');
            $table->string('industry', 120)->nullable()->after('geo_location');
            $table->index('geo_location');
            $table->index('industry');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['geo_location']);
            $table->dropIndex(['industry']);
            $table->dropColumn(['geo_location', 'industry']);
        });
    }
};
