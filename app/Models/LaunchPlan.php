<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaunchPlan extends Model
{
    protected $fillable = [
        'customer_id', 'customer_product_id', 'title', 'plan',
        'target_date', 'status', 'launched_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['target_date' => 'date', 'launched_at' => 'datetime'];
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function customerProduct() { return $this->belongsTo(CustomerProduct::class); }
    public function dependencies() { return $this->hasMany(LaunchDependency::class); }
}
