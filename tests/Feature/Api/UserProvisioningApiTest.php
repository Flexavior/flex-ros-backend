<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_senior_management_can_list_provisioning_queue(): void
    {
        $this->seedRoles();
        $sr = User::where('email', 'senior.mgmt@mss.test')->first();
        $staffRoleId = Role::where('code', Role::STAFF)->value('id');

        $jit = User::factory()->create([
            'email' => 'jit@mss.test',
            'role_id' => $staffRoleId,
            'team_id' => null,
        ]);
        UserIdentity::create([
            'user_id' => $jit->id,
            'provider' => 'microsoft',
            'provider_user_id' => 'ms-123',
            'email' => 'jit@mss.test',
        ]);

        $res = $this->actingAs($sr)->getJson('/api/v1/users/provisioning');
        $res->assertOk();
        $emails = collect($res->json('users'))->pluck('email');
        $this->assertContains('jit@mss.test', $emails->all());
    }

    public function test_sales_cannot_access_provisioning(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();

        $this->actingAs($sales)->getJson('/api/v1/users/provisioning')->assertStatus(403);
    }

    public function test_senior_management_can_assign_sales_role(): void
    {
        $this->seedRoles();
        $sr = User::where('email', 'senior.mgmt@mss.test')->first();
        $staffRoleId = Role::where('code', Role::STAFF)->value('id');
        $team = Team::first();

        $jit = User::factory()->create([
            'role_id' => $staffRoleId,
            'team_id' => null,
        ]);

        $this->actingAs($sr)->putJson("/api/v1/users/{$jit->id}/assign-role", [
            'role_code' => Role::SALES,
            'team_id' => $team->id,
        ])->assertOk()
            ->assertJsonPath('role.code', Role::SALES);

        $this->assertSame(Role::SALES, $jit->fresh()->role->code);
    }

    public function test_admin_overview_requires_admin(): void
    {
        $this->seedRoles();
        $sales = User::where('email', 'sales@mss.test')->first();
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($sales)->getJson('/api/v1/admin/overview')->assertStatus(403);
        $this->actingAs($admin)->getJson('/api/v1/admin/overview')->assertOk()
            ->assertJsonStructure(['users', 'auth', 'realtime', 'crm']);
    }
}
