<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agreement extends Model
{
    public const TYPE_NDA = 'nda';
    public const TYPE_MOU = 'mou';
    public const TYPE_CONTRACT = 'contract';

    protected $fillable = [
        'customer_id', 'type', 'title', 'document_url', 'status',
        'sent_at', 'signed_at', 'signatory_name', 'signatory_title',
        'value', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'signed_at' => 'datetime', 'value' => 'decimal:2'];
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
