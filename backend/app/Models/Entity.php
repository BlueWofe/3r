<?php

namespace App\Models;

use App\Models\Concerns\HasPrison;
use App\Services\GroupAudience;
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
        if (in_array($this->type, ['contents', 'meetings', 'resources'])) {
            $data['group_ids'] = $data['group_ids'] ?? [];
            $data['group_names'] = app(GroupAudience::class)->names($data['group_ids']);
        }
        if ($this->type === 'meetings') {
            $data += ['kind' => 'meeting', 'show_on_calendar' => false, 'color' => '#967323'];
            $ids = array_values(array_unique(array_map('intval', $data['role_ids'] ?? [])));
            $names = Role::whereIn('id', $ids)->pluck('name', 'id');
            $data['role_names'] = array_values(array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids), fn ($name) => $name !== null));
        }
        if ($this->type === 'contents') {
            $data['version'] = $data['version'] ?? 1;
            $data['visibility'] = $data['visibility'] ?? 'public';
            if (($data['kind'] ?? '') === 'news') {
                $data['article_type'] = $data['article_type'] ?? (($data['category'] ?? '') === '見證分享' ? 'testimony' : 'news');
            }
        }
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
