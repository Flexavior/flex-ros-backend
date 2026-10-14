<?php

namespace Database\Seeders;

use App\Models\ChannelIntegration;
use App\Models\ModuleRegistry;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Default checklists, channel integration stubs, module registry (no marketing channels). */
class CrmChecklistsAndIntegrationsSeeder extends Seeder
{
    public function run(): void
    {
        $this->putSettingIfMissing('crm.checklists.onboarding', [
            ['title' => 'Client document provisioning collected', 'is_required' => true],
            ['title' => 'Client logo received', 'is_required' => true],
            ['title' => 'Bank account details verified', 'is_required' => true],
        ], 'checklists');

        $this->putSettingIfMissing('crm.checklists.contract_sign_off', [
            ['title' => 'NDA signed', 'is_required' => true],
            ['title' => 'MoU signed', 'is_required' => false],
            ['title' => 'Contract signed by both parties', 'is_required' => true],
            ['title' => 'Advance payment received', 'is_required' => false],
        ], 'checklists');

        $this->putSettingIfMissing('crm.checklists.launch', [
            ['title' => 'All external dependencies completed', 'is_required' => true],
            ['title' => 'Client sign-off on launch date', 'is_required' => true],
        ], 'checklists');

        foreach ([
            ['provider' => 'viber', 'display_name' => 'Viber Business'],
            ['provider' => 'telegram', 'display_name' => 'Telegram Bot'],
            ['provider' => 'discord', 'display_name' => 'Discord'],
            ['provider' => 'teams', 'display_name' => 'Microsoft Teams'],
        ] as $ch) {
            ChannelIntegration::updateOrCreate(
                ['provider' => $ch['provider']],
                $ch + ['status' => 'disconnected']
            );
        }

        foreach ([
            ['code' => 'finance', 'name' => 'Finance', 'base_path' => '/api/v1/finance'],
            ['code' => 'project_management', 'name' => 'Project Management', 'base_path' => '/api/v1/pm'],
            ['code' => 'hr', 'name' => 'HR', 'base_path' => '/api/v1/hr'],
        ] as $m) {
            ModuleRegistry::updateOrCreate(['code' => $m['code']], $m + ['status' => 'available']);
        }
    }

    protected function putSettingIfMissing(string $key, mixed $value, string $group): void
    {
        if (Setting::query()->where('key', $key)->exists()) {
            return;
        }

        Setting::put($key, $value, $group);
    }
}
