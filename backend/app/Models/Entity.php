<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Entity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function publicData(): array
    {
        return array_merge($this->data, ['id' => $this->id, 'owner_id' => $this->owner_id, 'created_at' => $this->created_at]);
    }
}
