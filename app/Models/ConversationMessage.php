<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'channel', 'direction', 'body',
        'sender_name', 'sender_ref', 'external_message_id', 'sent_by', 'sent_at', 'meta',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'meta' => 'array'];
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function sentBy() { return $this->belongsTo(User::class, 'sent_by'); }
}
