<?php

namespace App\Models;

use App\Domain\Documents\DocumentLibraryAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'code', 'title', 'category', 'library', 'storage_path', 'uploaded_by',
        'allowed_user_ids', 'is_active',
    ];

    protected $casts = [
        'allowed_user_ids' => 'array',
        'is_active' => 'boolean',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function userMayDownload(User $user): bool
    {
        return DocumentLibraryAccess::userMayDownload($user, $this);
    }
}
