<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    /** Gateway channel codes (ConvyMes) — extend by adding an adapter there + a row here. */
    public const CHANNEL_FACEBOOK = 'facebook';
    public const CHANNEL_VIBER = 'viber';
    public const CHANNEL_LINE = 'line';

    public const STATUS_UNASSIGNED = 'unassigned';
    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLOSED = 'closed';

    public const OPEN_STATUSES = [self::STATUS_UNASSIGNED, self::STATUS_OPEN, self::STATUS_PENDING];

    protected $fillable = [
        'channel', 'external_id', 'customer_ref', 'customer_name', 'subject',
        'status', 'owner_id', 'team_id', 'claimed_at', 'last_message_at',
        'unread_count', 'lead_id', 'customer_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'last_message_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function team() { return $this->belongsTo(Team::class); }
    public function lead() { return $this->belongsTo(Lead::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function messages() { return $this->hasMany(ConversationMessage::class)->orderBy('sent_at'); }
    public function events() { return $this->hasMany(ConversationEvent::class)->orderBy('created_at'); }

    public function isUnowned(): bool
    {
        return $this->owner_id === null;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeChannel(Builder $q, ?string $channel): Builder
    {
        return $channel ? $q->where('channel', $channel) : $q;
    }
}
