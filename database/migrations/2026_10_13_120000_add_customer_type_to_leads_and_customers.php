<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Org-type labels often stored on customer_segment before customer_type existed. */
    private const LEGACY_SEGMENT_AS_TYPE = [
        'Private School',
        'International School',
        'Private Institute',
        'Training Center',
        'Education Organization',
        'Distributor',
        'Individual',
        'NGO / Government',
        'Other',
        'School',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('leads', 'customer_type')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->string('customer_type', 120)->nullable()->after('customer_segment');
            });
        }

        if (!Schema::hasColumn('customers', 'customer_type')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('customer_type', 120)->nullable()->after('customer_segment');
            });
        }

        foreach (['leads', 'customers'] as $table) {
            if (!Schema::hasColumn($table, 'customer_type')) {
                continue;
            }
            DB::table($table)
                ->whereNull('customer_type')
                ->whereIn('customer_segment', self::LEGACY_SEGMENT_AS_TYPE)
                ->update(['customer_type' => DB::raw('customer_segment')]);
        }
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'customer_type')) {
                $table->dropColumn('customer_type');
            }
        });
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'customer_type')) {
                $table->dropColumn('customer_type');
            }
        });
    }
};
