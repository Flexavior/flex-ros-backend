<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingChannel extends Model
{
    protected $fillable = ['name', 'code', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function campaigns()
    {
        return $this->hasMany(MarketingCampaign::class, 'channel_id');
    }
}
