<?php

namespace App\Domain\Crm;

use InvalidArgumentException;

/** Canonical customer lifecycle status (DB stores lowercase). */
final class CustomerStatus
{
    public const ACTIVE = 'active';

    public const ONBOARDING = 'onboarding';

    public const CHURNED = 'churned';

    public const CANONICAL = [self::ACTIVE, self::ONBOARDING, self::CHURNED];

    /**
     * Map legacy/import labels (e.g. SQL "Active") to canonical values.
     */
    public static function normalize(?string $value): string
    {
        $raw = strtolower(trim((string) $value));

        return match ($raw) {
            'active', 'act' => self::ACTIVE,
            'onboarding', 'on-boarding', 'on board' => self::ONBOARDING,
            'churned', 'inactive', 'in-active', 'closed', 'disabled' => self::CHURNED,
            self::ACTIVE, self::ONBOARDING, self::CHURNED => $raw,
            '' => self::ACTIVE,
            default => self::ACTIVE,
        };
    }

    public static function assertCanonical(string $value): string
    {
        if (!in_array($value, self::CANONICAL, true)) {
            throw new InvalidArgumentException('The selected status is invalid.');
        }

        return $value;
    }

    public static function label(string $canonical): string
    {
        return match ($canonical) {
            self::ONBOARDING => 'Onboarding',
            self::CHURNED => 'Inactive',
            default => 'Active',
        };
    }
}
