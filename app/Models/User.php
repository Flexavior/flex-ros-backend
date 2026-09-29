<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'supervisor_id',
        'team_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function subordinates()
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'owner_id');
    }

    public function hasRole(string $code): bool
    {
        return $this->role?->code === $code;
    }

    public function hasAnyRole(array $codes): bool
    {
        return in_array($this->role?->code, $codes, true);
    }

    /** CEO / Senior Management / Admin see the whole organisation. */
    public function isOrgLevel(): bool
    {
        return $this->hasAnyRole(Role::ORG_LEVEL);
    }

    /** Can approve campaigns / contract sign-offs. */
    public function canApprove(): bool
    {
        return $this->hasAnyRole(Role::APPROVERS);
    }

    /** May own/answer omnichannel conversations. */
    public function isInboxAgent(): bool
    {
        return $this->hasAnyRole(Role::INBOX_AGENTS);
    }

    /** May assign/reassign conversation flow to other users. */
    public function isInboxManager(): bool
    {
        return $this->hasAnyRole(Role::INBOX_MANAGERS);
    }
}

