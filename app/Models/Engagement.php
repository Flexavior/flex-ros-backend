<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Engagement extends Model
{
    protected $fillable = [
        'lead_id',
        'user_id',
        'channel',
        'summary',
        'next_action',
        'next_action_at',
        'status_updated_at',
        'occurred_at',
        'contact_method',
        'contact_person',
        'purpose',
        'activity_outcome',
        'customer_response',
        'next_follow_up_at',
        'assigned_owner_id',
        'completed',
        'notes',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'next_action_at' => 'datetime',
            'status_updated_at' => 'datetime',
            'occurred_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'completed' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    public function lead() { return $this->belongsTo(Lead::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function assignedOwner() { return $this->belongsTo(User::class, 'assigned_owner_id'); }
}
