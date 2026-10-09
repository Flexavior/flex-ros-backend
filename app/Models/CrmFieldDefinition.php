<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmFieldDefinition extends Model
{
    protected $fillable = [
        'entity',
        'field_key',
        'label',
        'field_type',
        'options',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
