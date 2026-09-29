<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistCompletion extends Model
{
    protected $fillable = [
        'customer_id', 'checklist_item_id', 'is_done', 'done_by', 'done_at', 'note',
    ];

    protected function casts(): array
    {
        return ['is_done' => 'boolean', 'done_at' => 'datetime'];
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function item() { return $this->belongsTo(StageChecklistItem::class, 'checklist_item_id'); }
    public function doneBy() { return $this->belongsTo(User::class, 'done_by'); }
}
