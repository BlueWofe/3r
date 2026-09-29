<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceSession extends Model
{
    protected $attributes = ['version' => 1];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'session_id');
    }
}
