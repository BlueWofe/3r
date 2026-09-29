<?php

namespace App\Models;

use App\Models\Concerns\HasPrison;
use Illuminate\Database\Eloquent\Model;

class Entity extends Model
{
    use HasPrison;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function publicData(): array
    {
        $data = $this->type === 'cases' ? $this->prisonData() : $this->data;
        if ($this->type === 'cases') {
            $data['assigned_user_name'] = isset($data['assigned_user_id']) ? User::find($data['assigned_user_id'])?->name : null;
            $data['records'] = array_map(function ($record) {
                $record['author_name'] = isset($record['author_id']) ? User::find($record['author_id'])?->name : null;

                return $record;
            }, $data['records'] ?? []);
        }

        return array_merge($data, ['id' => $this->id, 'owner_id' => $this->owner_id, 'created_at' => $this->created_at]);
    }
}
