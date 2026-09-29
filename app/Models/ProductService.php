<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductService extends Model
{
    protected $table = 'products_services';

    protected $fillable = ['code', 'name', 'type', 'description', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_products', 'product_service_id', 'customer_id')
            ->withPivot(['agreed_price', 'status'])
            ->withTimestamps();
    }
}
