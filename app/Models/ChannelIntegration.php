<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelIntegration extends Model
{
    public const PROVIDER_VIBER = 'viber';
    public const PROVIDER_TELEGRAM = 'telegram';
    public const PROVIDER_DISCORD = 'discord';
    public const PROVIDER_TEAMS = 'teams';

    protected $fillable = ['provider', 'display_name', 'credentials', 'status', 'last_synced_at', 'config'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'config' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function messages()
    {
        return $this->hasMany(IntegrationMessage::class, 'channel_integration_id');
    }
}
