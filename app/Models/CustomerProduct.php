<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProduct extends Model
{
    protected $table = 'customer_products';

    protected $fillable = ['customer_id', 'product_service_id', 'agreed_price', 'status'];

    protected function casts(): array
    {
        return ['agreed_price' => 'decimal:2'];
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function productService() { return $this->belongsTo(ProductService::class); }
}
