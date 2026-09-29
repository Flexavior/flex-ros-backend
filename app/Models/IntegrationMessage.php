<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationMessage extends Model
{
    protected $fillable = [
        'channel_integration_id', 'direction', 'external_ref', 'contact_name',
        'contact_handle', 'body', 'attachments', 'lead_id', 'customer_id',
        'assigned_to', 'status', 'received_at',
    ];

    protected function casts(): array
    {
        return ['attachments' => 'array', 'received_at' => 'datetime'];
    }

    public function channel() { return $this->belongsTo(ChannelIntegration::class, 'channel_integration_id'); }
    public function lead() { return $this->belongsTo(Lead::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to'); }
}
