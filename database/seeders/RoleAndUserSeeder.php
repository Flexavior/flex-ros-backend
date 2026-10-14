<?php

namespace Database\Seeders;

use App\Models\Role;
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

        $this->call([
            CrmStagesAndSettingsSeeder::class,
            CrmChecklistsAndIntegrationsSeeder::class,
            MarketingChannelSeeder::class,
        ]);
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
}
