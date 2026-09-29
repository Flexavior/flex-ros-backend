<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_APPOINTMENT = 'appointment';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_LOST = 'lost';

    public const OPEN_STATUSES = [
        self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_QUALIFIED, self::STATUS_APPOINTMENT,
    ];

    protected $fillable = [
        'name', 'company', 'email', 'phone', 'source', 'status', 'notes',
        'owner_id', 'stage_id', 'customer_id', 'status_updated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Auto-maintain the stale-detection timestamp
        static::creating(function (Lead $lead) {
            $lead->status_updated_at ??= now();
            $lead->created_by ??= auth()->id();
        });

        static::updating(function (Lead $lead) {
            if ($lead->isDirty('status')) {
                $lead->status_updated_at = now();
        }
        });
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function stage() { return $this->belongsTo(PipelineStage::class); }
    public function customer() { return $this->hasOne(Customer::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public function engagements() { return $this->hasMany(Engagement::class); }
    public function appointments() { return $this->hasMany(Appointment::class); }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeStale(Builder $q, int $days): Builder
    {
        return $q->open()
            ->where('status_updated_at', '<=', now()->subDays($days));
    }
}
