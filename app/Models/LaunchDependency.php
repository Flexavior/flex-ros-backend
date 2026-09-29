<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchDependency extends Model
{
    protected $fillable = [
        'launch_plan_id', 'title', 'type', 'status', 'due_date', 'is_blocking', 'notes',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'is_blocking' => 'boolean'];
    }

    public function launchPlan() { return $this->belongsTo(LaunchPlan::class); }
}
