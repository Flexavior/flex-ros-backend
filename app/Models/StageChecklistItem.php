<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StageChecklistItem extends Model
{
    protected $fillable = ['stage_id', 'title', 'description', 'is_required', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function stage()
    {
        return $this->belongsTo(PipelineStage::class);
    }
}
