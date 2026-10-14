<?php

namespace App\Support;

use App\Models\Setting;

final class SsoSetting
{
    public static function settingKey(string $provider): string
    {
        return 'auth.sso.'.strtolower(trim($provider)).'_enabled';
    }

    public static function coerceBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            if ($value === []) {
                return false;
            }

            return self::coerceBool(reset($value));
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function isProviderEnabled(string $provider): bool
    {
        $key = self::settingKey($provider);
        // Read from DB (not settings.all cache): manual SQL edits and stale cache must not hide login buttons.
        $setting = Setting::query()->where('key', $key)->first();
        if ($setting !== null) {
            return self::coerceBool($setting->value);
        }

        return (bool) config('services.sso.'.strtolower(trim($provider)).'.enabled', false);
    }
}
