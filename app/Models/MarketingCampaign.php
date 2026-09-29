<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'channel_id', 'product_service_id', 'owner_id', 'name', 'objective',
        'budget', 'start_date', 'end_date', 'approval_status',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['budget' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'approved_at' => 'datetime'];
    }

    public function channel() { return $this->belongsTo(MarketingChannel::class, 'channel_id'); }
    public function productService() { return $this->belongsTo(ProductService::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
    public function posts() { return $this->hasMany(MarketingPost::class, 'campaign_id'); }
}
