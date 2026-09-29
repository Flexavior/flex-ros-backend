<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleRegistry extends Model
{
    protected $table = 'module_registry';

    protected $fillable = ['code', 'name', 'version', 'base_path', 'status', 'manifest'];

    protected function casts(): array
    {
        return ['manifest' => 'array'];
    }
}
