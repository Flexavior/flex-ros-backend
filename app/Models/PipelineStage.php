<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    protected $fillable = ['code', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function checklistItems()
    {
        return $this->hasMany(StageChecklistItem::class, 'stage_id');
    }
}
