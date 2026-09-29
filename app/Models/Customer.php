<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id', 'name', 'company', 'email', 'phone', 'status',
        'lead_id', 'owner_id', 'created_by',
    ];

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
