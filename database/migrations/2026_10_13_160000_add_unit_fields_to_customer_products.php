<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->decimal('unit', 14, 4)->nullable()->after('agreed_price');
            $table->decimal('unit_price', 14, 2)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropColumn(['unit', 'unit_price']);
        });
    }
};
