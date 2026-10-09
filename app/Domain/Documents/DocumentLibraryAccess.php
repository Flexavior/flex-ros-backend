<?php

namespace App\Domain\Documents;

use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\User;

final class DocumentLibraryAccess
{
    public const LIBRARY_SALES = 'sales';

    public const LIBRARY_MARKETING = 'marketing';

    public const LIBRARIES = [self::LIBRARY_SALES, self::LIBRARY_MARKETING];

    public const CATEGORIES = ['nda', 'mou', 'contract', 'proposal', 'brochure'];

    /** Operational CRM users (not system administrator). */
    public static function isCrmOperationalUser(User $user): bool
    {
        return !$user->hasRole(Role::ADMIN);
    }

    public static function userMayAccessCrmModule(User $user): bool
    {
        return self::isCrmOperationalUser($user);
    }

    public static function userMayViewLibrary(User $user, string $library): bool
    {
        if (!self::isCrmOperationalUser($user)) {
            return false;
        }

        if ($user->isOrgLevel() && !$user->hasRole(Role::ADMIN)) {
            return true;
        }

        return self::userBelongsToLibraryAudience($user, $library);
    }

    public static function userMayDownload(User $user, DocumentTemplate $template): bool
    {
        if (!$template->is_active) {
            return false;
        }

        return self::userMayViewLibrary($user, (string) $template->library);
    }

    public static function userMayUploadToLibrary(User $user, string $library): bool
    {
        if (!self::isCrmOperationalUser($user)) {
            return false;
        }

        if (!in_array($library, self::LIBRARIES, true)) {
            return false;
        }

        if ($user->hasRole(Role::SENIOR_STAFF)) {
            return true;
        }

        if ($library === self::LIBRARY_SALES && $user->hasRole(Role::SALES)) {
            return true;
        }

        if ($library === self::LIBRARY_MARKETING && $user->hasRole(Role::MARKETING)) {
            return true;
        }

        return false;
    }

    /** Libraries the user may upload into (for UI). */
    public static function uploadLibrariesFor(User $user): array
    {
        $out = [];
        foreach (self::LIBRARIES as $lib) {
            if (self::userMayUploadToLibrary($user, $lib)) {
                $out[] = $lib;
            }
        }

        return $out;
    }

    private static function userBelongsToLibraryAudience(User $user, string $library): bool
    {
        $code = $user->role?->code;

        if (in_array($code, [Role::STAFF, Role::SENIOR_STAFF, Role::SUPERVISOR, Role::CUSTOMER_SERVICE], true)) {
            return true;
        }

        if ($library === self::LIBRARY_SALES && $code === Role::SALES) {
            return true;
        }

        if ($library === self::LIBRARY_MARKETING && $code === Role::MARKETING) {
            return true;
        }

        return false;
    }
}
