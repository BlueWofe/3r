<?php

namespace App\Models;

use App\Models\Concerns\HasPrison;
use Illuminate\Database\Eloquent\Model;

class ServiceSession extends Model
{
    use HasPrison;

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
