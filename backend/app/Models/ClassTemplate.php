<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassTemplate extends Model
{
    protected $guarded = [];

    protected $attributes = ['version' => 1, 'active' => true];

    protected function casts(): array
    {
        return ['data' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function publicData(): array
    {
        return $this->data + ['id' => $this->id, 'version' => $this->version, 'active' => $this->active];
    }
}
