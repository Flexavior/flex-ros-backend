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

    /** Stages where conversion UI/action is enabled. */
    public const CONVERT_ELIGIBLE_STAGES = [
        'Qualified',
        'Demo / Meeting',
        'Proposal',
        'Negotiation',
        'Won',
    ];

    protected $fillable = [
        'name', 'company', 'email', 'phone', 'source', 'status', 'notes',
        'owner_id', 'stage_id', 'customer_id', 'status_updated_at', 'created_by',
        'lead_source', 'product_interest', 'customer_segment', 'geo_location', 'industry',
        'contact_role', 'current_stage', 'interest_level', 'buying_timeline', 'primary_contact_method',
        'last_activity_outcome', 'custom_fields',
    ];

    protected $appends = [
        'idle_touch_count',
        'needs_qualify_review',
        'qualify_idle_alert',
        'convert_eligible',
    ];

    protected function casts(): array
    {
        return [
            'status_updated_at' => 'datetime',
            'custom_fields' => 'array',
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
            if ($lead->isDirty('status') || $lead->isDirty('current_stage')) {
                $lead->status_updated_at = now();
            }
        });
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function stage() { return $this->belongsTo(PipelineStage::class); }
    public function customer() { return $this->hasOne(Customer::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public function engagements() { return $this->hasMany(Engagement::class); }
    public function contacts() { return $this->hasMany(LeadContact::class)->orderByDesc('is_primary')->orderBy('id'); }
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

    public function scopeWithQualifyIdleMetrics(Builder $q): Builder
    {
        return $q->withCount([
            'engagements as idle_touch_count' => function (Builder $eq) {
                $progress = static::qualifyProgressOutcomes();
                $eq->where(function (Builder $w) use ($progress) {
                    $w->whereNull('activity_outcome')
                        ->orWhereNotIn('activity_outcome', $progress);
                });
            },
        ]);
    }

    public static function qualifyDefaults(): array
    {
        return [
            'max_idle_touches' => 4,
            'progress_outcomes' => ['Demo booked', 'Proposal sent', 'Won'],
        ];
    }

    public static function qualifyConfig(): array
    {
        $defaults = static::qualifyDefaults();
        $raw = Setting::get('crm.qualify', []);
        if (!is_array($raw)) {
            return $defaults;
        }

        $max = (int) ($raw['max_idle_touches'] ?? $defaults['max_idle_touches']);
        $max = max(1, min($max, 50));

        $outcomes = collect($raw['progress_outcomes'] ?? $defaults['progress_outcomes'])
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v))
            ->unique()
            ->values()
            ->all();
        if ($outcomes === []) {
            $outcomes = $defaults['progress_outcomes'];
        }

        return [
            'max_idle_touches' => $max,
            'progress_outcomes' => $outcomes,
        ];
    }

    public static function qualifyProgressOutcomes(): array
    {
        return static::qualifyConfig()['progress_outcomes'];
    }

    public static function qualifyMaxIdleTouches(): int
    {
        return (int) static::qualifyConfig()['max_idle_touches'];
    }

    public function getIdleTouchCountAttribute(): int
    {
        if (array_key_exists('idle_touch_count', $this->attributes)) {
            return (int) $this->attributes['idle_touch_count'];
        }

        $progress = static::qualifyProgressOutcomes();

        return $this->engagements()
            ->where(function (Builder $q) use ($progress) {
                $q->whereNull('activity_outcome')->orWhereNotIn('activity_outcome', $progress);
            })
            ->count();
    }

    public function getNeedsQualifyReviewAttribute(): bool
    {
        return $this->idle_touch_count >= static::qualifyMaxIdleTouches();
    }

    public function getQualifyIdleAlertAttribute(): bool
    {
        return $this->needs_qualify_review;
    }

    public function getConvertEligibleAttribute(): bool
    {
        if (in_array((string) $this->current_stage, static::CONVERT_ELIGIBLE_STAGES, true)) {
            return true;
        }

        return in_array((string) $this->status, [
            self::STATUS_QUALIFIED,
            self::STATUS_APPOINTMENT,
            self::STATUS_CONVERTED,
        ], true);
    }
}
