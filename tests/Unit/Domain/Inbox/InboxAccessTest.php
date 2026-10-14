<?php

namespace Tests\Unit\Domain\Inbox;

use App\Domain\Inbox\InboxAccess;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RoleAndUserSeeder::class);
    }

    public function test_defaults_all_inbox_agent_roles_when_setting_missing(): void
    {
        $this->seedRoles();

        $this->assertEquals(Role::INBOX_AGENTS, InboxAccess::enabledRoleCodes());
    }

    public function test_setting_narrows_enabled_roles(): void
    {
        $this->seedRoles();
        Setting::put(InboxAccess::SETTING_KEY, [Role::CUSTOMER_SERVICE, Role::CEO]);

        $this->assertEquals([Role::CUSTOMER_SERVICE, Role::CEO], InboxAccess::enabledRoleCodes());
    }

    public function test_marketing_never_passes_even_if_in_setting(): void
    {
        $this->seedRoles();
        Setting::put(InboxAccess::SETTING_KEY, [Role::MARKETING, Role::SALES]);

        $this->assertEquals([Role::SALES], InboxAccess::enabledRoleCodes());

        $marketing = User::where('email', 'marketing@mss.test')->first();
        $this->assertFalse(InboxAccess::userMayAccess($marketing));
    }

    public function test_disabled_role_in_setting_denies_user(): void
    {
        $this->seedRoles();
        Setting::put(InboxAccess::SETTING_KEY, [Role::CEO]);

        $cs = User::where('email', 'cs@mss.test')->first();
        $this->assertFalse(InboxAccess::userMayAccess($cs));
    }
}
