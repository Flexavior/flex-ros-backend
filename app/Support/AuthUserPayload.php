<?php

namespace App\Support;

use App\Domain\Inbox\InboxAccess;
use App\Models\User;

final class AuthUserPayload
{
    /** @return array<string, mixed> */
    public static function from(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->only(['code', 'name', 'level']),
            'supervisor' => $user->supervisor?->only(['id', 'name']),
            'team' => $user->team?->only(['id', 'name']),
            'is_active' => $user->is_active,
            'can_access_inbox' => InboxAccess::userMayAccess($user),
        ];
    }
}
