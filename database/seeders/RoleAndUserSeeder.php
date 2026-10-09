<?php

namespace Database\Seeders;

use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Role hierarchy + reporting lines + teams + default settings.
     * See docs/03-User-Definitions.md.
     */
    public function run(): void
    {
        // ---- Roles (level 1 = top / upstream) ----
        $roles = [
            ['code' => Role::CEO, 'name' => 'CEO', 'level' => 1, 'description' => 'Steering strategic decisions; full org visibility'],
            ['code' => Role::SENIOR_MANAGEMENT, 'name' => 'Senior Management', 'level' => 1, 'description' => 'Strategic decisions; portfolio dashboards; approvals'],
            ['code' => Role::SUPERVISOR, 'name' => 'Supervisor', 'level' => 2, 'description' => 'Team management; downstream and upstream reporting'],
            ['code' => Role::SENIOR_STAFF, 'name' => 'Senior Staff', 'level' => 3, 'description' => 'Own + downstream works of assigned staff'],
            ['code' => Role::STAFF, 'name' => 'Staff', 'level' => 4, 'description' => 'Assigned task execution'],
            ['code' => Role::SALES, 'name' => 'Sales Team', 'level' => 4, 'description' => 'Leads, follow-ups, appointments, conversion'],
            ['code' => Role::CUSTOMER_SERVICE, 'name' => 'Customer Service', 'level' => 4, 'description' => 'Omnichannel inbox — claim, reply, and route customer queries'],
            ['code' => Role::MARKETING, 'name' => 'Marketing Team', 'level' => 4, 'description' => 'Campaign planning, posting schedule'],
            ['code' => Role::ADMIN, 'name' => 'System Administrator', 'level' => 99, 'description' => 'Settings, users, roles'],
        ];

        $roleIds = [];
        foreach ($roles as $r) {
            $roleIds[$r['code']] = Role::updateOrCreate(['code' => $r['code']], $r)->id;
        }

        $this->seedUsers($roleIds);
        $this->seedStagesAndSettings();
        $this->seedChecklistsAndIntegrations();
    }

    protected function seedUsers(array $roleIds): void
    {
        // ---- Team (unit) ----
        $team = Team::updateOrCreate(['name' => 'Sales & Marketing Unit A'], []);

        // ---- Users (reporting lines) ----
        $users = [
            ['name' => 'MSS CEO', 'email' => 'ceo@mss.test', 'role' => Role::CEO, 'supervisor' => null],
            ['name' => 'Senior Manager', 'email' => 'senior.mgmt@mss.test', 'role' => Role::SENIOR_MANAGEMENT, 'supervisor' => null],
            ['name' => 'Supervisor A', 'email' => 'supervisor@mss.test', 'role' => Role::SUPERVISOR, 'supervisor' => 'senior.mgmt@mss.test'],
            ['name' => 'Senior Staff', 'email' => 'senior.staff@mss.test', 'role' => Role::SENIOR_STAFF, 'supervisor' => 'supervisor@mss.test'],
            ['name' => 'Staff Member', 'email' => 'staff@mss.test', 'role' => Role::STAFF, 'supervisor' => 'senior.staff@mss.test'],
            ['name' => 'Sales Rep', 'email' => 'sales@mss.test', 'role' => Role::SALES, 'supervisor' => 'supervisor@mss.test'],
            ['name' => 'Customer Service Agent', 'email' => 'cs@mss.test', 'role' => Role::CUSTOMER_SERVICE, 'supervisor' => 'supervisor@mss.test'],
            ['name' => 'Marketer', 'email' => 'marketing@mss.test', 'role' => Role::MARKETING, 'supervisor' => 'supervisor@mss.test'],
            ['name' => 'System Admin', 'email' => 'admin@mss.test', 'role' => Role::ADMIN, 'supervisor' => null],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $roleIds[$u['role']],
                    'supervisor_id' => $u['supervisor'] ? User::where('email', $u['supervisor'])->value('id') : null,
                    'team_id' => $team->id,
                    'is_active' => true,
                ]
            );
        }

        // Supervisor owns the team
        $team->update(['owner_id' => User::where('email', 'supervisor@mss.test')->value('id')]);

        // Keep the team_user pivot in sync with users.team_id (unit membership)
        $teamMemberIds = User::where('team_id', $team->id)->pluck('id')->all();
        $team->members()->sync($teamMemberIds);
    }

    protected function seedStagesAndSettings(): void
    {
        // ---- Pipeline stages (drives dynamic checklists) ----
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

        // ---- Default system settings (System Settings module) ----
        Setting::put('crm.stale_task_days', 5, 'dashboard');
        Setting::put('crm.client_id_prefix', 'CUS', 'crm');
        Setting::put('auth.sso.microsoft_enabled', false, 'auth');
        Setting::put('auth.sso.google_enabled', false, 'auth');
        Setting::put('auth.sso.allowed_email_domains', [], 'auth');
        Setting::put('crm.lead_picklists', [
            'lead_source' => ['Referrals', 'Organic Search', 'Paid Ads', 'Social Media', 'Website', 'Events', 'Partner', 'Walk-in', 'Other'],
            'product_interest' => ['CRM', 'Marketing', 'Project Management', 'HR', 'Finance', 'Custom Integration', 'Other'],
            'customer_segment' => ['Startup', 'SME', 'Enterprise', 'Government', 'Non-profit', 'Education', 'Other'],
            'contact_role' => ['Owner', 'Director', 'Manager', 'Staff', 'Procurement', 'IT', 'Other'],
            'current_stage' => ['New', 'Contacted', 'Qualified', 'Demo / Meeting', 'Proposal', 'Negotiation', 'Won', 'Lost'],
            'interest_level' => ['Hot', 'Warm', 'Cold'],
            'buying_timeline' => ['Immediate', '1 Month', '3 Months', '6 Months', 'Unknown'],
            'contact_method' => ['Call', 'Email', 'Viber', 'LINE', 'Facebook', 'Telegram', 'Meeting', 'Other'],
            'activity_outcome' => ['No response', 'Connected', 'Follow-up needed', 'Demo booked', 'Proposal sent', 'Won', 'Lost'],
            'completed' => ['Yes', 'No'],
        ], 'crm');
    }

    protected function seedChecklistsAndIntegrations(): void
    {
        // Default dynamic checklists — fully editable via System Settings (add/remove)
        Setting::put('crm.checklists.onboarding', [
            ['title' => 'Client document provisioning collected', 'is_required' => true],
            ['title' => 'Client logo received', 'is_required' => true],
            ['title' => 'Bank account details verified', 'is_required' => true],
        ], 'checklists');

        Setting::put('crm.checklists.contract_sign_off', [
            ['title' => 'NDA signed', 'is_required' => true],
            ['title' => 'MoU signed', 'is_required' => false],
            ['title' => 'Contract signed by both parties', 'is_required' => true],
            ['title' => 'Advance payment received', 'is_required' => false],
        ], 'checklists');

        Setting::put('crm.checklists.launch', [
            ['title' => 'All external dependencies completed', 'is_required' => true],
            ['title' => 'Client sign-off on launch date', 'is_required' => true],
        ], 'checklists');

        // ---- Channel integrations (Viber/Telegram/Discord/Teams — Phase 2) ----
        foreach ([
            ['provider' => 'viber', 'display_name' => 'Viber Business'],
            ['provider' => 'telegram', 'display_name' => 'Telegram Bot'],
            ['provider' => 'discord', 'display_name' => 'Discord'],
            ['provider' => 'teams', 'display_name' => 'Microsoft Teams'],
        ] as $ch) {
            \App\Models\ChannelIntegration::updateOrCreate(
                ['provider' => $ch['provider']],
                $ch + ['status' => 'disconnected']
            );
        }

        // ---- Module registry (Finance / PM / HR extendability) ----
        foreach ([
            ['code' => 'finance', 'name' => 'Finance', 'base_path' => '/api/v1/finance'],
            ['code' => 'project_management', 'name' => 'Project Management', 'base_path' => '/api/v1/pm'],
            ['code' => 'hr', 'name' => 'HR', 'base_path' => '/api/v1/hr'],
        ] as $m) {
            \App\Models\ModuleRegistry::updateOrCreate(['code' => $m['code']], $m + ['status' => 'available']);
        }

        // ---- Digital marketing channels (planning / posting schedule register) ----
        foreach ([
            ['name' => 'Facebook', 'code' => 'facebook', 'type' => 'social'],
            ['name' => 'LinkedIn', 'code' => 'linkedin', 'type' => 'social'],
            ['name' => 'Telegram', 'code' => 'telegram', 'type' => 'messaging'],
            ['name' => 'Viber', 'code' => 'viber', 'type' => 'messaging'],
            ['name' => 'Google Search', 'code' => 'google_search', 'type' => 'search'],
            ['name' => 'Website / SEO', 'code' => 'website', 'type' => 'other'],
            ['name' => 'Email Newsletter', 'code' => 'email', 'type' => 'email'],
        ] as $ch) {
            \App\Models\MarketingChannel::updateOrCreate(['code' => $ch['code']], $ch + ['is_active' => true]);
        }
    }
}
