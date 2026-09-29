<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id', 'user_id', 'title', 'agenda', 'scheduled_at', 'location', 'status', 'outcome',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function lead() { return $this->belongsTo(Lead::class); }
    public function user() { return $this->belongsTo(User::class); }
}
