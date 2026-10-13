<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['leads', 'customers'] as $tableName) {
            if (!Schema::hasColumn($tableName, 'organization_type')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('organization_type', 120)->nullable()->after('customer_segment');
                });
            }
            if (Schema::hasColumn($tableName, 'customer_type')) {
                \Illuminate\Support\Facades\DB::table($tableName)
                    ->whereNull('organization_type')
                    ->whereNotNull('customer_type')
                    ->update(['organization_type' => \Illuminate\Support\Facades\DB::raw('customer_type')]);
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('customer_type');
                });
            }
        }

        $picklists = Setting::get('crm.lead_picklists', []);
        if (is_array($picklists) && isset($picklists['customer_type']) && !isset($picklists['organization_type'])) {
            $picklists['organization_type'] = $picklists['customer_type'];
            unset($picklists['customer_type']);
            Setting::put('crm.lead_picklists', $picklists, 'crm');
        }
    }

    public function down(): void
    {
        $picklists = Setting::get('crm.lead_picklists', []);
        if (is_array($picklists) && isset($picklists['organization_type']) && !isset($picklists['customer_type'])) {
            $picklists['customer_type'] = $picklists['organization_type'];
            unset($picklists['organization_type']);
            Setting::put('crm.lead_picklists', $picklists, 'crm');
        }
    }
};
