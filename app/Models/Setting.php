<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }

    /** Get a setting value (array/scalar) with cache. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings.all', fn () => self::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default;
    }

    /** Insert or update a setting and flush the cache. */
    public static function put(string $key, mixed $value, string $group = 'crm', ?int $updatedBy = null): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'updated_by' => $updatedBy ?? auth()->id()]
        );

        Cache::forget('settings.all');

        return $setting;
    }
}
