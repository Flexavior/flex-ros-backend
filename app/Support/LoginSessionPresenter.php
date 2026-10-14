<?php

namespace App\Support;

use App\Models\User;

/** Records login timestamps and builds auth JSON for SPA login / SSO exchange. */
final class LoginSessionPresenter
{
    /** @return array<string, mixed> */
    public static function userPayload(User $user, bool $recordLogin): array
    {
        $user->loadMissing('role', 'supervisor', 'team');

        $welcomeKind = null;
        if ($recordLogin) {
            $welcomeKind = $user->first_login_at === null ? 'first' : 'returning';
            if ($user->first_login_at === null) {
                $user->first_login_at = now();
            }
            $user->last_login_at = now();
            $user->save();
        }

        $payload = AuthUserPayload::from($user);
        if ($welcomeKind !== null) {
            $payload['welcome_kind'] = $welcomeKind;
        }

        return $payload;
    }
}
