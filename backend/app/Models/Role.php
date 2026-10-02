<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $attributes = ['active' => true];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'active' => 'boolean'];
    }
}
