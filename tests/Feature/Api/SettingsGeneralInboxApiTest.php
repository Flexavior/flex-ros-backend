<?php

namespace Tests\Feature\Api;

use App\Domain\Inbox\InboxAccess;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsGeneralInboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_admin_can_persist_inbox_roles_with_dotted_json_key(): void
    {
        $this->seedRoles();
        $admin = User::where('email', 'admin@mss.test')->first();

        $this->actingAs($admin)
            ->putJson('/api/v1/settings/general', [
                'crm.inbox_enabled_roles' => [Role::SENIOR_MANAGEMENT, Role::CUSTOMER_SERVICE],
            ])
            ->assertOk()
            ->assertJson([
                'crm.inbox_enabled_roles' => [Role::SENIOR_MANAGEMENT, Role::CUSTOMER_SERVICE],
            ]);

        $this->assertEquals(
            [Role::SENIOR_MANAGEMENT, Role::CUSTOMER_SERVICE],
            InboxAccess::enabledRoleCodes()
        );

        $staff = User::where('email', 'staff@mss.test')->first();
        $this->assertFalse(InboxAccess::userMayAccess($staff));
    }
}
