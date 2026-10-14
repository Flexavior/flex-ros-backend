<?php

namespace App\Domain\Inbox;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;

/** Settings-backed inbox visibility; {@see Role::INBOX_AGENTS} is the maximum role set. */
final class InboxAccess
{
    public const SETTING_KEY = 'crm.inbox_enabled_roles';

    /** @return list<string> */
    public static function defaultEnabledRoleCodes(): array
    {
        return Role::INBOX_AGENTS;
    }

    /** @return list<string> */
    public static function enabledRoleCodes(): array
    {
        $stored = Setting::get(self::SETTING_KEY);
        if (!is_array($stored)) {
            return self::defaultEnabledRoleCodes();
        }

        return array_values(array_intersect(
            array_map(static fn ($c) => (string) $c, $stored),
            Role::INBOX_AGENTS
        ));
    }

    public static function userMayAccess(?User $user): bool
    {
        $code = $user?->role?->code;
        if (!$code || !in_array($code, Role::INBOX_AGENTS, true)) {
            return false;
        }

        return in_array($code, self::enabledRoleCodes(), true);
    }

    /** @return list<array{code: string, name: string}> */
    public static function roleCatalog(): array
    {
        $roles = Role::query()
            ->whereIn('code', Role::INBOX_AGENTS)
            ->orderBy('level')
            ->get(['code', 'name']);

        return $roles->map(static fn (Role $r) => [
            'code' => $r->code,
            'name' => $r->name,
        ])->values()->all();
    }
}
