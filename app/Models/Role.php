<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['code', 'name', 'level', 'description'];

    public const CEO = 'ceo';
    public const SENIOR_MANAGEMENT = 'senior_management';
    public const SUPERVISOR = 'supervisor';
    public const SENIOR_STAFF = 'senior_staff';
    public const STAFF = 'staff';
    public const SALES = 'sales';
    public const MARKETING = 'marketing';
    public const CUSTOMER_SERVICE = 'customer_service';
    public const ADMIN = 'admin';

    /** Role codes that have organisation-wide visibility. */
    public const ORG_LEVEL = [self::CEO, self::SENIOR_MANAGEMENT, self::ADMIN];

    /** Roles allowed to approve campaigns / sign-offs. */
    public const APPROVERS = [self::CEO, self::SENIOR_MANAGEMENT, self::SUPERVISOR];

    /**
     * Roles that may own/answer an omnichannel conversation.
     * `customer_service` is seeded now so conversations can be delegated to a
     * dedicated service desk without a schema or code change later.
     */
    public const INBOX_AGENTS = [
        self::CUSTOMER_SERVICE, self::SALES, self::STAFF, self::SENIOR_STAFF, self::SUPERVISOR,
        self::SENIOR_MANAGEMENT, self::CEO, self::ADMIN,
    ];

    /** Roles that may assign conversations to other users (flow control). */
    public const INBOX_MANAGERS = [self::SUPERVISOR, self::SENIOR_MANAGEMENT, self::CEO, self::ADMIN];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
