<?php

namespace App\Models;

use App\Domain\Crm\CustomerStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'name', 'company', 'customer_segment', 'industry', 'geo_location',
        'email', 'phone', 'status', 'address', 'lead_id', 'owner_id', 'created_by',
    ];

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => CustomerStatus::normalize($value),
            set: fn (?string $value) => CustomerStatus::normalize($value),
        );
    }

    public function lead() { return $this->belongsTo(Lead::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public function products()
    {
        return $this->belongsToMany(ProductService::class, 'customer_products', 'customer_id', 'product_service_id')
            ->withPivot(['agreed_price', 'status'])
            ->withTimestamps();
    }

    public function customerProducts() { return $this->hasMany(CustomerProduct::class); }
    public function agreements() { return $this->hasMany(Agreement::class); }
    public function launchPlans() { return $this->hasMany(LaunchPlan::class); }
    public function checklistCompletions() { return $this->hasMany(ChecklistCompletion::class); }
}
