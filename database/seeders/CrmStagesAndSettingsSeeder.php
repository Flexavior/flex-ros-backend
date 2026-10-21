<?php

namespace Database\Seeders;

use App\Domain\Crm\DashboardConfig;
use App\Domain\Crm\LeadPicklistService;
use App\Models\PipelineStage;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Pipeline stages + default CRM settings (does not touch users/roles). */
class CrmStagesAndSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            ['code' => 'lead_capture', 'name' => 'Lead Capture', 'sort_order' => 1],
            ['code' => 'qualification', 'name' => 'Qualification & Follow-up', 'sort_order' => 2],
            ['code' => 'appointment', 'name' => 'Appointment & Proposal', 'sort_order' => 3],
            ['code' => 'onboarding', 'name' => 'Customer Onboarding', 'sort_order' => 4],
            ['code' => 'contract_sign_off', 'name' => 'NDA / MoU / Contract Sign-off', 'sort_order' => 5],
            ['code' => 'launch', 'name' => 'Launch', 'sort_order' => 6],
        ];

        foreach ($stages as $s) {
            PipelineStage::updateOrCreate(['code' => $s['code']], $s);
        }

        $this->putSettingIfMissing('crm.stale_task_days', 5, 'dashboard');
        $this->putSettingIfMissing(DashboardConfig::MQL_STAGES_KEY, DashboardConfig::DEFAULT_MQL_STAGES, 'dashboard');
        $this->putSettingIfMissing(DashboardConfig::SQL_STAGES_KEY, DashboardConfig::DEFAULT_SQL_STAGES, 'dashboard');
        $this->putSettingIfMissing(DashboardConfig::PIPELINE_STAGES_KEY, DashboardConfig::DEFAULT_PIPELINE_STAGES, 'dashboard');
        $this->putSettingIfMissing('crm.client_id_prefix', 'CUS', 'crm');
        $this->putSettingIfMissing('auth.sso.microsoft_enabled', false, 'auth');
        $this->putSettingIfMissing('auth.sso.google_enabled', false, 'auth');
        $this->putSettingIfMissing('auth.sso.allowed_email_domains', [], 'auth');
        $this->putSettingIfMissing(
            LeadPicklistService::SETTINGS_KEY,
            (new LeadPicklistService)->defaults(),
            'crm'
        );
    }

    protected function putSettingIfMissing(string $key, mixed $value, string $group): void
    {
        if (Setting::query()->where('key', $key)->exists()) {
            return;
        }

        Setting::put($key, $value, $group);
    }
}
