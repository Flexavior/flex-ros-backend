<?php

namespace App\Support;

final class SafeReturnPath
{
    /** SPA-relative path only (prevents open redirects). */
    public static function normalize(?string $path, string $default = '/'): string
    {
        $path = trim((string) $path);
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $default;
        }

        if (strlen($path) > 200) {
            return $default;
        }

        return $path;
    }
}
