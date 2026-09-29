<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Engagement extends Model
{
    protected $fillable = [
        'lead_id', 'user_id', 'channel', 'summary', 'next_action', 'next_action_at', 'status_updated_at',
    ];

    protected function casts(): array
    {
        return ['next_action_at' => 'datetime', 'status_updated_at' => 'datetime'];
    }

    public function lead() { return $this->belongsTo(Lead::class); }
    public function user() { return $this->belongsTo(User::class); }
}
