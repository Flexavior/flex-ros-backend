<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'email',
        'raw_claims',
    ];

    protected function casts(): array
    {
        return [
            'raw_claims' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
