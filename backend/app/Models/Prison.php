<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prison extends Model
{
    protected $guarded = [];

    protected $attributes = ['address' => null, 'active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    public function publicData(): array
    {
        return $this->only(['id', 'name', 'address', 'active', 'version']);
    }
}
