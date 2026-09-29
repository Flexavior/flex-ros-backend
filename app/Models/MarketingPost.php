<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingPost extends Model
{
    protected $fillable = [
        'campaign_id', 'title', 'content', 'scheduled_at', 'published_at',
        'status', 'media_url', 'reach', 'leads_generated',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function campaign() { return $this->belongsTo(MarketingCampaign::class, 'campaign_id'); }
}
