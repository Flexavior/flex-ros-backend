<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadContact extends Model
{
    protected $fillable = [
        'lead_id',
        'name',
        'role',
        'email',
        'phone',
        'viber_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
