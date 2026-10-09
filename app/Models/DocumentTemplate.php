<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'code', 'title', 'category', 'storage_path', 'allowed_user_ids', 'is_active',
    ];

    protected $casts = [
        'allowed_user_ids' => 'array',
        'is_active' => 'boolean',
    ];

    public function userMayDownload(User $user): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($user->hasRole('admin') || $user->hasRole('senior_management') || $user->hasRole('ceo')) {
            return true;
        }

        $ids = $this->allowed_user_ids;
        if ($ids === null || $ids === []) {
            return false;
        }

        return in_array($user->id, array_map('intval', $ids), true);
    }
}
