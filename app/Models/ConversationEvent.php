<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationEvent extends Model
{
    public const TYPE_INGESTED = 'ingested';
    public const TYPE_CLAIMED = 'claimed';
    public const TYPE_ASSIGNED = 'assigned';
    public const TYPE_REASSIGNED = 'reassigned';
    public const TYPE_RELEASED = 'released';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_REPLIED = 'replied';
    public const TYPE_LINKED = 'linked';
    public const TYPE_NOTE = 'note';

    protected $fillable = [
        'conversation_id', 'type', 'actor_id', 'from_owner_id', 'to_owner_id', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function fromOwner() { return $this->belongsTo(User::class, 'from_owner_id'); }
    public function toOwner() { return $this->belongsTo(User::class, 'to_owner_id'); }
}
