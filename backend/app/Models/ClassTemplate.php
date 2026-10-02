<?php

namespace App\Models;

use App\Models\Concerns\HasPrison;
use Illuminate\Database\Eloquent\Model;

class ClassTemplate extends Model
{
    use HasPrison;

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'active' => true];

    protected function casts(): array
    {
        return ['data' => 'array', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function publicData(): array
    {
        return $this->prisonData() + ['id' => $this->id, 'version' => $this->version, 'active' => $this->active];
    }
}
